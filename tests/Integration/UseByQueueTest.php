<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use UseByQueue;

final class UseByQueueTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;

    protected function setUp(): void
    {
        if (!getenv('KEEPLORE_TEST_DB_HOST')) {
            $this->markTestSkipped('Set KEEPLORE_TEST_DB_HOST to run MySQL integration tests.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli(
            getenv('KEEPLORE_TEST_DB_HOST'),
            getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            '',
            (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306)
        );
        $this->databaseName = 'keeplore_test_' . bin2hex(random_bytes(6));
        $this->db->query('CREATE DATABASE ' . $this->databaseName);
        $this->db->select_db($this->databaseName);
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        require_once PRIVATE_PATH . '/classes/UseByQueue.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
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

    private function queue(string $today = '2026-06-01'): UseByQueue
    {
        return new UseByQueue($this->db, 1, $today);
    }

    private function ids(array $entries): array
    {
        return array_map(fn (array $entry) => (int) $entry['id'], $entries);
    }

    public function test_the_queue_holds_the_owners_kept_items_not_to_get_rid_of(): void
    {
        // Catan is kept; Azul is to get rid of; Arrival is only in the
        // secondary collection; Former possession is not kept; item 20 is
        // another owner's.
        $this->assertSame([10], $this->ids($this->queue()->entries()));
    }

    public function test_the_secondary_collection_joins_the_queue_when_asked(): void
    {
        $this->assertSame([12, 10], $this->ids($this->queue()->entries(['include_secondary_collection' => true])));
    }

    public function test_snoozed_items_are_shown_unless_hidden(): void
    {
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05' WHERE id = 10");
        $this->assertSame([10], $this->ids($this->queue()->entries()));
        $this->assertSame([], $this->ids($this->queue()->entries(['hide_snoozed' => true])));
        $this->assertSame([10], $this->ids($this->queue('2026-06-05')->entries(['hide_snoozed' => true])));
    }

    public function test_an_entry_carries_last_use_use_by_date_days_until_and_status(): void
    {
        $entry = $this->queue()->entries()[0];
        $this->assertSame('Catan', $entry['Title']);
        $this->assertSame('board-game', $entry['type']);
        $this->assertSame('2026-02-01', $entry['last_use']);
        $this->assertSame('2026-07-31', $entry['use_by_date']);
        $this->assertSame(60, $entry['days_until']);
        $this->assertSame('upcoming', $entry['status']);
    }

    public function test_a_legacy_play_date_later_than_the_latest_use_is_the_last_use(): void
    {
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-01-15')");
        $this->db->query("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-03-01'), (10, 1, '2026-02-20')");
        $entries = $this->queue()->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('2026-03-01', $entries[0]['last_use']);
        $this->assertSame('2026-08-28', $entries[0]['use_by_date']);
    }

    public function test_a_later_use_wins_over_an_older_legacy_play_date(): void
    {
        $this->db->query("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-01-10')");
        $this->assertSame('2026-02-01', $this->queue()->entries()[0]['last_use']);
    }

    public function test_the_items_own_frequency_wins_over_the_pages_interval(): void
    {
        $this->db->query('UPDATE games SET interaction_frequency_days = 10 WHERE id = 10');
        $this->assertSame('2026-02-21', $this->queue()->entries(['default_interval' => 30])[0]['use_by_date']);
    }

    public function test_without_its_own_frequency_an_item_uses_the_pages_interval_or_the_owners_default(): void
    {
        $this->db->query('UPDATE games SET interaction_frequency_days = NULL WHERE id = 10');
        (new \Preferences($this->db, 1))->save(['default_use_interval' => 45]);
        $this->assertSame('2026-04-02', $this->queue()->entries(['default_interval' => 30])[0]['use_by_date']);
        $this->assertSame('2026-05-02', $this->queue()->entries()[0]['use_by_date']);
    }

    public function test_status_turns_on_the_pinned_today(): void
    {
        $this->assertSame([1, 'upcoming'], $this->dueness($this->queue('2026-07-30')->entries()[0]));
        $this->assertSame([0, 'due_today'], $this->dueness($this->queue('2026-07-31')->entries()[0]));
        $this->assertSame([-1, 'overdue'], $this->dueness($this->queue('2026-08-01')->entries()[0]));
    }

    private function dueness(array $entry): array
    {
        return [$entry['days_until'], $entry['status']];
    }

    public function test_type_filtering(): void
    {
        $queue = $this->queue();
        $this->assertSame([12], $this->ids($queue->entries(['include_secondary_collection' => true, 'type_ids' => [2]])));
        $this->assertSame([12, 10], $this->ids($queue->entries(['include_secondary_collection' => true, 'type_ids' => null])));
        $this->assertSame([], $queue->entries(['include_secondary_collection' => true, 'type_ids' => []]));
    }

    public function test_items_without_a_use_by_date_sort_last(): void
    {
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, Acq) VALUES (14, 1, 'Undated', 1, NULL), (15, 1, 'Early', 1, '2025-01-01')");
        $entries = $this->queue()->entries();
        $this->assertSame([15, 10, 14], $this->ids($entries));
        $this->assertNull($entries[2]['last_use']);
        $this->assertNull($entries[2]['use_by_date']);
        $this->assertNull($entries[2]['days_until']);
        $this->assertNull($entries[2]['status']);
    }

    public function test_items_due_the_same_day_are_ordered_by_last_use(): void
    {
        // Catan: last used 2026-02-01, frequency 90, due 2026-07-31.
        // Never used, acquired 2026-05-02 with frequency 90: also due 2026-07-31.
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, Acq) VALUES (16, 1, 'Unplayed', 1, '2026-05-02')");
        $entries = $this->queue()->entries();
        $this->assertSame(['2026-07-31', '2026-07-31'], array_column($entries, 'use_by_date'));
        $this->assertSame([16, 10], $this->ids($entries));
    }

    public function test_sweet_spot_and_minimum_age_filters(): void
    {
        $this->db->query("UPDATE games SET ss = '2, 3', Age = 10 WHERE id = 10");
        $this->db->query("UPDATE games SET ss = '4', Age = 8 WHERE id = 12");
        $queue = $this->queue();
        $all = ['include_secondary_collection' => true];
        $this->assertSame([10], $this->ids($queue->entries($all + ['sweet_spot' => '3'])));
        $this->assertSame([12], $this->ids($queue->entries($all + ['sweet_spot' => '4'])));
        $this->assertSame([10], $this->ids($queue->entries($all + ['minimum_age' => 9])));
        $this->assertSame([12, 10], $this->ids($queue->entries($all + ['minimum_age' => '0', 'sweet_spot' => ''])));
    }

    public function test_entry_returns_one_of_the_owners_items_whatever_its_kept_state(): void
    {
        $queue = $this->queue();
        $this->assertSame($queue->entries()[0], $queue->entry(10));
        $former = $queue->entry(13);
        $this->assertSame('Former possession', $former['Title']);
        $this->assertNull($former['last_use']);
        $this->assertSame('2026-04-01', $former['use_by_date']);
        $this->assertSame('overdue', $former['status']);
        $this->assertSame('Azul', $queue->entry(11)['Title']);
    }

    public function test_entry_is_null_for_another_owners_item_or_no_item(): void
    {
        $this->assertNull($this->queue()->entry(20));
        $this->assertNull($this->queue()->entry(999));
    }

    public function test_another_owners_uses_and_items_never_reach_the_queue(): void
    {
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (20, 2, '2026-05-01')");
        $this->assertSame([], (new UseByQueue($this->db, 3, '2026-06-01'))->entries());
        $this->assertSame([20], $this->ids((new UseByQueue($this->db, 2, '2026-06-01'))->entries()));
    }

    public function test_today_defaults_to_the_record_use_day(): void
    {
        $this->assertSame(record_use_today(), (new UseByQueue($this->db, 1))->today());
    }
}
