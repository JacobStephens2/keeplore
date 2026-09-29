<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Settings queues a full import of the owner's BGG reviewer, the cron worker
 * runs it, and Settings reads its progress back while it runs.
 */
final class BggImportJobsTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;

    protected function setUp(): void
    {
        if (!getenv('KEEPLORE_TEST_DB_HOST')) {
            $this->markTestSkipped('Set KEEPLORE_TEST_DB_HOST to run MySQL integration tests.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = $this->connect();
        $this->databaseName = 'keeplore_test_' . bin2hex(random_bytes(6));
        $this->db->query('CREATE DATABASE ' . $this->databaseName);
        $this->db->select_db($this->databaseName);
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        foreach (['add-item-bgg-url', 'add-item-bgg-ratings', 'add-item-bgg-ratings-manual', 'add-user-bgg-username', 'add-bgg-import-jobs', 'add-bgg-import-jobs'] as $migration) {
            $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/' . $migration . '.sql'));
        }
        require_once PRIVATE_PATH . '/bgg_import_jobs.php';

        // Fixture items 10-13 belong to user 1, item 20 to user 2.
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/147154' WHERE id = 10");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/29107' WHERE id = 11");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/421' WHERE id = 20");
        $this->db->query("UPDATE users SET bgg_username = 'Gyges' WHERE id = 1");
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function connect(): \mysqli
    {
        return new \mysqli(
            getenv('KEEPLORE_TEST_DB_HOST'),
            getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            '',
            (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306)
        );
    }

    private function runSql(string $sql): void
    {
        $this->db->multi_query($sql);
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    /** A stand-in for BGG: Gyges rated 147154 and has no entry for 29107. */
    private function fakeBgg(): callable
    {
        return function (string $url) {
            if (str_contains($url, '/users?')) {
                return str_contains($url, 'username=Gyges') ? '[{"userid":63428,"username":"Gyges"}]' : '[]';
            }
            if (str_contains($url, 'objectid=147154')) {
                return json_encode(['items' => [['rating' => 9.5, 'textfield' => ['comment' => ['value' => 'Great.']]]]]);
            }
            return json_encode(['items' => []]);
        };
    }

    public function test_queueing_needs_a_reviewer_on_settings(): void
    {
        $result = bgg_import_job_queue($this->db, 2);

        $this->assertSame(['ok' => false, 'error' => 'Name a BoardGameGeek reviewer above first.'], $result);
        $this->assertNull(bgg_import_job_latest($this->db, 2));
    }

    public function test_queued_job_waits_for_the_worker(): void
    {
        $result = bgg_import_job_queue($this->db, 1);

        $this->assertTrue($result['ok']);
        $job = bgg_import_job_latest($this->db, 1);
        $this->assertSame('Gyges', $job['bgg_username']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame('Import of Gyges queued. It starts within a minute.', $result['message']);
        $this->assertSame(
            ['active' => true, 'can_queue' => false, 'text' => 'Import of Gyges queued. It starts within a minute.'],
            bgg_import_job_view($this->db, 1)
        );
        $this->assertSame(['active' => false, 'can_queue' => false, 'text' => ''], bgg_import_job_view($this->db, 2));
        $this->assertNull(bgg_import_job_latest($this->db, 2));
    }

    public function test_a_second_import_waits_for_the_first(): void
    {
        bgg_import_job_queue($this->db, 1);

        $again = bgg_import_job_queue($this->db, 1);

        $this->assertSame(['ok' => false, 'error' => 'An import of Gyges is already queued or running.'], $again);
        $this->assertSame('1', (string) $this->db->query('SELECT COUNT(*) FROM bgg_import_jobs')->fetch_row()[0]);
    }

    public function test_worker_runs_the_import_and_records_what_it_did(): void
    {
        bgg_import_job_queue($this->db, 1);

        $ran = bgg_import_jobs_run_queued($this->db, $this->fakeBgg(), 0);

        $this->assertSame(1, $ran);
        $job = bgg_import_job_latest($this->db, 1);
        $this->assertSame('done', $job['status']);
        $this->assertSame(2, $job['total']);
        $this->assertSame(2, $job['checked']);
        $this->assertSame(1, $job['imported']);
        $this->assertSame(0, $job['failed']);
        $this->assertNotNull($job['finished_at']);
        $this->assertStringStartsWith('Imported Gyges on ', bgg_import_job_status_text($job));
        $this->assertStringEndsWith(': checked 2 items, 1 rated or commented, 0 removed, 0 failed.', bgg_import_job_status_text($job));
        $this->assertTrue(bgg_import_job_view($this->db, 1)['can_queue']);
        $this->assertSame(9.5, find_item_bgg_ratings($this->db, [10], 1)[10]['Gyges']['rating']);
        $this->assertSame(0, bgg_import_jobs_run_queued($this->db, $this->fakeBgg(), 0));
    }

    public function test_status_counts_one_item_as_one(): void
    {
        $job = ['bgg_username' => 'Gyges', 'status' => 'done', 'total' => 1, 'checked' => 1, 'imported' => 1, 'removed' => 0, 'failed' => 0, 'finished_at' => '2026-09-29 12:00:00'];

        $this->assertSame('Imported Gyges on 2026-09-29: checked 1 item, 1 rated or commented, 0 removed, 0 failed.', bgg_import_job_status_text($job));
        $this->assertSame('Importing Gyges: checked 0 of 1 item, 0 rated or commented so far.', bgg_import_job_status_text(['status' => 'running', 'checked' => 0, 'imported' => 0] + $job));
    }

    public function test_worker_records_progress_while_it_runs(): void
    {
        bgg_import_job_queue($this->db, 1);
        $seen = [];
        $bgg = $this->fakeBgg();
        $watching = function (string $url) use ($bgg, &$seen) {
            if (str_contains($url, 'objectid=')) {
                $seen[] = bgg_import_job_status_text(bgg_import_job_latest($this->db, 1));
            }
            return $bgg($url);
        };

        bgg_import_jobs_run_queued($this->db, $watching, 0);

        $this->assertSame([
            'Importing Gyges: checked 0 of 2 items, 0 rated or commented so far.',
            'Importing Gyges: checked 1 of 2 items, 1 rated or commented so far.',
        ], $seen);
    }

    public function test_worker_marks_a_failed_import(): void
    {
        $this->db->query("UPDATE users SET bgg_username = 'Nobody' WHERE id = 1");
        bgg_import_job_queue($this->db, 1);

        bgg_import_jobs_run_queued($this->db, $this->fakeBgg(), 0);

        $job = bgg_import_job_latest($this->db, 1);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('Import of Nobody failed: No BoardGameGeek user named Nobody.', bgg_import_job_status_text($job));
        $this->assertTrue(bgg_import_job_queue($this->db, 1)['ok']);
    }

    public function test_a_job_the_worker_abandoned_does_not_block_the_next(): void
    {
        bgg_import_job_queue($this->db, 1);
        $this->db->query("UPDATE bgg_import_jobs SET status = 'running', updated_at = NOW() - INTERVAL 11 MINUTE");

        $result = bgg_import_job_queue($this->db, 1);

        $this->assertTrue($result['ok']);
        $statuses = $this->db->query('SELECT status, error FROM bgg_import_jobs ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $this->assertSame([
            ['status' => 'failed', 'error' => 'The import stopped before it finished.'],
            ['status' => 'queued', 'error' => null],
        ], $statuses);
    }

    public function test_a_job_no_worker_picked_up_fails_and_says_so(): void
    {
        bgg_import_job_queue($this->db, 1);
        $this->db->query("UPDATE bgg_import_jobs SET created_at = NOW() - INTERVAL 11 MINUTE, updated_at = NOW() - INTERVAL 11 MINUTE");

        $job = bgg_import_job_latest($this->db, 1);

        $this->assertSame('failed', $job['status']);
        $this->assertSame('Import of Gyges failed: It never started. The background worker may not be running.', bgg_import_job_status_text($job));
        $this->assertTrue(bgg_import_job_queue($this->db, 1)['ok']);
    }

    public function test_a_job_waiting_behind_a_running_import_keeps_waiting(): void
    {
        $this->db->query("UPDATE users SET bgg_username = 'Gyges' WHERE id = 2");
        bgg_import_job_queue($this->db, 2);
        bgg_import_job_queue($this->db, 1);
        $this->db->query("UPDATE bgg_import_jobs SET status = 'running' WHERE user_id = 2");
        $this->db->query("UPDATE bgg_import_jobs SET created_at = NOW() - INTERVAL 11 MINUTE, updated_at = NOW() - INTERVAL 11 MINUTE WHERE user_id = 1");

        $this->assertSame('queued', bgg_import_job_latest($this->db, 1)['status']);
    }

    public function test_a_failed_import_keeps_how_far_it_got(): void
    {
        bgg_import_job_queue($this->db, 1);
        // Item 11's comment is too long for the narrowed column, so storing it
        // throws after item 10 was imported.
        $this->db->query('ALTER TABLE item_bgg_ratings MODIFY comment VARCHAR(10)');
        $bgg = $this->fakeBgg();
        $long_comment = function (string $url) use ($bgg) {
            if (str_contains($url, 'objectid=29107')) {
                return json_encode(['items' => [['rating' => 8, 'textfield' => ['comment' => ['value' => str_repeat('x', 20)]]]]]);
            }
            return $bgg($url);
        };

        bgg_import_jobs_run_queued($this->db, $long_comment, 0);

        $job = bgg_import_job_latest($this->db, 1);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('The import hit an unexpected error.', $job['error']);
        $this->assertSame(2, $job['total']);
        $this->assertSame(1, $job['checked']);
        $this->assertSame(1, $job['imported']);
    }

    public function test_a_job_marked_stale_stays_failed_when_its_worker_wakes(): void
    {
        bgg_import_job_queue($this->db, 1);
        $bgg = $this->fakeBgg();
        $slow = function (string $url) use ($bgg) {
            if (str_contains($url, 'objectid=29107')) {
                $this->db->query("UPDATE bgg_import_jobs SET status = 'failed', error = 'The import stopped before it finished.'");
            }
            return $bgg($url);
        };

        bgg_import_jobs_run_queued($this->db, $slow, 0);

        $job = bgg_import_job_latest($this->db, 1);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('The import stopped before it finished.', $job['error']);
    }

    public function test_only_one_worker_runs_at_a_time(): void
    {
        bgg_import_job_queue($this->db, 1);
        $other = $this->connect();
        $other->query("SELECT GET_LOCK('keeplore_bgg_import_jobs', 0)");

        $ran = bgg_import_jobs_run_queued($this->db, $this->fakeBgg(), 0);
        $other->close();

        $this->assertSame(0, $ran);
        $this->assertSame('queued', bgg_import_job_latest($this->db, 1)['status']);
    }
}
