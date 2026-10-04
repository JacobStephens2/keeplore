<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Settings queues a full import of the owner's BGG reviewer, the cron worker
 * runs it, and Settings reads its progress back while it runs.
 */
final class BggImportsTest extends TestCase
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
        require_once PRIVATE_PATH . '/classes/BggImports.php';

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

    private function imports(int $userId): \BggImports
    {
        return new \BggImports($this->db, $userId);
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

    /** The fake BGG, calling $atItem with the URL before it answers for each item. */
    private function during(callable $atItem): callable
    {
        $bgg = $this->fakeBgg();
        return function (string $url) use ($bgg, $atItem) {
            if (str_contains($url, 'objectid=')) {
                $atItem($url);
            }
            return $bgg($url);
        };
    }
    private function today(): string
    {
        return (string) $this->db->query('SELECT CURDATE()')->fetch_row()[0];
    }

    public function test_queueing_needs_a_reviewer_on_settings(): void
    {
        $result = $this->imports(2)->queue();

        $this->assertSame(['ok' => false, 'error' => 'Name a BoardGameGeek reviewer above first.'], $result);
        $this->assertSame(['active' => false, 'can_queue' => false, 'text' => ''], $this->imports(2)->status());
    }

    public function test_queued_import_waits_for_the_worker(): void
    {
        $result = $this->imports(1)->queue();

        $this->assertSame(['ok' => true, 'message' => 'Import of Gyges queued. It starts within a minute.'], $result);
        $this->assertSame(
            ['active' => true, 'can_queue' => false, 'text' => 'Import of Gyges queued. It starts within a minute.'],
            $this->imports(1)->status()
        );
        $this->assertSame(['active' => false, 'can_queue' => false, 'text' => ''], $this->imports(2)->status());
    }

    public function test_a_second_import_waits_for_the_first(): void
    {
        $this->imports(1)->queue();

        $again = $this->imports(1)->queue();

        $this->assertSame(['ok' => false, 'error' => 'An import of Gyges is already queued or running.'], $again);
        $this->assertSame('1', (string) $this->db->query('SELECT COUNT(*) FROM bgg_import_jobs')->fetch_row()[0]);
    }

    public function test_worker_runs_the_import_and_reports_what_it_did(): void
    {
        $this->imports(1)->queue();

        $ran = \BggImports::runQueued($this->db, $this->fakeBgg(), 0);

        $this->assertSame(1, $ran);
        $this->assertSame([
            'active' => false,
            'can_queue' => true,
            'text' => 'Imported Gyges on ' . $this->today() . ': checked 2 items, 1 rated or commented, 0 removed, 0 failed.',
        ], $this->imports(1)->status());
        $this->assertSame(9.5, (new \BggRatings($this->db, 1))->forItems([10])[10]['Gyges']['rating']);
        $this->assertSame(0, \BggImports::runQueued($this->db, $this->fakeBgg(), 0));
    }

    public function test_status_counts_one_item_as_one(): void
    {
        $this->db->query('UPDATE games SET bgg_url = NULL WHERE id = 11');
        $this->imports(1)->queue();
        $seen = [];

        \BggImports::runQueued($this->db, $this->during(function () use (&$seen) {
            $seen[] = $this->imports(1)->status()['text'];
        }), 0);

        $this->assertSame(['Importing Gyges: checked 0 of 1 item, 0 rated or commented so far.'], $seen);
        $this->assertSame(
            'Imported Gyges on ' . $this->today() . ': checked 1 item, 1 rated or commented, 0 removed, 0 failed.',
            $this->imports(1)->status()['text']
        );
    }

    public function test_worker_reports_progress_while_it_runs(): void
    {
        $this->imports(1)->queue();
        $seen = [];

        \BggImports::runQueued($this->db, $this->during(function () use (&$seen) {
            $seen[] = $this->imports(1)->status();
        }), 0);

        $this->assertSame([
            ['active' => true, 'can_queue' => false, 'text' => 'Importing Gyges: checked 0 of 2 items, 0 rated or commented so far.'],
            ['active' => true, 'can_queue' => false, 'text' => 'Importing Gyges: checked 1 of 2 items, 1 rated or commented so far.'],
        ], $seen);
    }

    public function test_worker_reports_a_failed_import(): void
    {
        $this->db->query("UPDATE users SET bgg_username = 'Nobody' WHERE id = 1");
        $this->imports(1)->queue();

        \BggImports::runQueued($this->db, $this->fakeBgg(), 0);

        $this->assertSame(
            ['active' => false, 'can_queue' => true, 'text' => 'Import of Nobody failed: No BoardGameGeek user named Nobody.'],
            $this->imports(1)->status()
        );
        $this->assertTrue($this->imports(1)->queue()['ok']);
    }

    public function test_an_import_the_worker_abandoned_does_not_block_the_next(): void
    {
        $this->imports(1)->queue();
        $seen = [];

        // The worker goes quiet mid-import, and the owner looks and queues again.
        \BggImports::runQueued($this->db, $this->during(function () use (&$seen) {
            if ($seen === []) {
                $this->db->query('UPDATE bgg_import_jobs SET updated_at = NOW() - INTERVAL 11 MINUTE');
                $seen[] = $this->imports(1)->status();
                $seen[] = $this->imports(1)->queue();
            }
        }), 0);

        $this->assertSame([
            ['active' => false, 'can_queue' => true, 'text' => 'Import of Gyges failed: The import stopped before it finished.'],
            ['ok' => true, 'message' => 'Import of Gyges queued. It starts within a minute.'],
        ], $seen);
        $this->assertSame('2', (string) $this->db->query('SELECT COUNT(*) FROM bgg_import_jobs')->fetch_row()[0]);
    }

    public function test_an_import_no_worker_picked_up_fails_and_says_so(): void
    {
        $this->imports(1)->queue();
        $this->db->query("UPDATE bgg_import_jobs SET created_at = NOW() - INTERVAL 11 MINUTE, updated_at = NOW() - INTERVAL 11 MINUTE");

        $this->assertSame(
            ['active' => false, 'can_queue' => true, 'text' => 'Import of Gyges failed: It never started. The background worker may not be running.'],
            $this->imports(1)->status()
        );
        $this->assertTrue($this->imports(1)->queue()['ok']);
    }

    public function test_an_import_waiting_behind_a_running_one_keeps_waiting(): void
    {
        $this->db->query("UPDATE users SET bgg_username = 'Gyges' WHERE id = 2");
        $this->imports(2)->queue();
        $this->imports(1)->queue();
        $seen = [];

        // While user 2's import runs, user 1's has waited past the stale age.
        \BggImports::runQueued($this->db, $this->during(function () use (&$seen) {
            if ($seen === []) {
                $this->db->query('UPDATE bgg_import_jobs SET created_at = NOW() - INTERVAL 11 MINUTE, updated_at = NOW() - INTERVAL 11 MINUTE WHERE user_id = 1');
                $seen[] = $this->imports(1)->status();
            }
        }), 0);

        $this->assertSame(
            [['active' => true, 'can_queue' => false, 'text' => 'Import of Gyges queued. It starts within a minute.']],
            $seen
        );
    }

    public function test_a_database_error_fails_the_import_without_its_text(): void
    {
        $this->imports(1)->queue();
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

        \BggImports::runQueued($this->db, $long_comment, 0);

        $this->assertSame(
            ['active' => false, 'can_queue' => true, 'text' => 'Import of Gyges failed: The import hit an unexpected error.'],
            $this->imports(1)->status()
        );
        $this->assertSame(9.5, (new \BggRatings($this->db, 1))->forItems([10])[10]['Gyges']['rating']);
    }

    public function test_an_import_marked_stale_stays_failed_when_its_worker_wakes(): void
    {
        $this->imports(1)->queue();

        // Before item 11 the worker has been quiet past the stale age, and
        // Settings looks; then it wakes and carries on.
        \BggImports::runQueued($this->db, $this->during(function (string $url) {
            if (str_contains($url, 'objectid=29107')) {
                $this->db->query('UPDATE bgg_import_jobs SET updated_at = NOW() - INTERVAL 11 MINUTE');
                $this->imports(1)->status();
            }
        }), 0);

        $this->assertSame(
            'Import of Gyges failed: The import stopped before it finished.',
            $this->imports(1)->status()['text']
        );
    }

    public function test_only_one_worker_runs_at_a_time(): void
    {
        $this->imports(1)->queue();
        $other = $this->connect();
        $other->query("SELECT GET_LOCK('keeplore_bgg_import_jobs', 0)");

        $ran = \BggImports::runQueued($this->db, $this->fakeBgg(), 0);
        $other->close();

        $this->assertSame(0, $ran);
        $this->assertSame('Import of Gyges queued. It starts within a minute.', $this->imports(1)->status()['text']);
    }

}
