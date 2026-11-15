<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
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

    public function test_hide_snoozed_hides_exactly_the_entries_that_are_snoozed(): void
    {
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05' WHERE id = 10");
        foreach (['2026-06-04', '2026-06-05', '2026-06-06'] as $today) {
            $notSnoozed = array_filter($this->queue($today)->entries(), fn (array $entry) => !$entry['is_snoozed']);
            $this->assertSame($this->ids(array_values($notSnoozed)), $this->ids($this->queue($today)->entries(['hide_snoozed' => true])), $today);
        }
    }

    public function test_an_entry_is_snoozed_until_its_snoozed_until_day(): void
    {
        $this->assertFalse($this->queue()->entries()[0]['is_snoozed']);
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05' WHERE id = 10");
        $this->assertTrue($this->queue()->entries()[0]['is_snoozed']);
        $this->assertTrue($this->queue('2026-06-04')->entry(10)['is_snoozed']);
        $this->assertFalse($this->queue('2026-06-05')->entries()[0]['is_snoozed']);
        $this->assertFalse($this->queue('2026-06-05')->entry(10)['is_snoozed']);
    }

    public function test_an_entry_carries_last_use_interval_use_by_date_days_until_and_status(): void
    {
        $entry = $this->queue()->entries()[0];
        $this->assertSame(90.0, $entry['interval']);
        $this->assertSame('Catan', $entry['Title']);
        $this->assertSame('board-game', $entry['type']);
        $this->assertSame('2026-02-01', $entry['last_use']);
        $this->assertSame('2026-07-31', $entry['use_by_date']);
        $this->assertSame(60, $entry['days_until']);
        $this->assertSame('upcoming', $entry['status']);
    }

    public static function itemSchemas(): array
    {
        return ['legacy casing' => [false], 'canonical schema' => [true]];
    }

    #[DataProvider('itemSchemas')]
    public function test_entries_preserve_the_stored_fields_and_only_the_queue_projection(bool $canonicalSchema): void
    {
        if ($canonicalSchema) {
            $this->db->query('DROP TABLE games');
            $schema = file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
            preg_match('/CREATE TABLE IF NOT EXISTS games \(.*?\) ENGINE=[^;]*;/s', $schema, $match);
            $this->runSql($match[0]);
        } else {
            $this->db->query('DELETE FROM games');
            $this->db->query('ALTER TABLE games MODIFY interaction_frequency_days DECIMAL(8,2)');
        }
        $this->db->query("INSERT INTO games (id, user_id, Title, Acq, interaction_frequency_days, type_id, type,
                is_kept, is_in_secondary_collection, to_get_rid_of, mnp, mxp, mnt, mxt, Candidate, UsedRecUserCt, ss, Age)
            VALUES (10, 1, 'Catan', NULL, 10.50, 1, 'legacy type', 1, 0, 0, 2, 4, NULL, 45, ' 0 ', '007', '03,04', 8)");
        $this->db->query("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (1, 10, 'private tag')");

        $expected = [
            'id' => 10,
            'Title' => 'Catan',
            'Acq' => null,
            'interaction_frequency_days' => '10.50',
            'type_id' => 1,
            'type' => 'board-game',
            'user_id' => 1,
            'is_kept' => 1,
            'is_in_secondary_collection' => 0,
            'to_get_rid_of' => 0,
            'snoozed_until' => null,
            'mnp' => 2,
            'mxp' => 4,
            'mnt' => null,
            'mxt' => 45,
            'Candidate' => ' 0 ',
            'UsedRecUserCt' => $canonicalSchema ? '007' : 7,
            'ss' => '03,04',
            'age' => 8,
            'last_use' => '2026-02-01',
            'interval' => 10.5,
            'use_by_date' => '2026-02-22',
            'days_until' => -99,
            'status' => 'overdue',
            'is_snoozed' => false,
        ];
        $queue = $this->queue();
        $this->assertSame([$expected], $queue->entries());
        $this->assertSame($expected, $queue->entry(10));

        require_once PRIVATE_PATH . '/interact_by.php';
        $row = interact_by_present_row($queue->entry(10));
        $this->assertSame([3, 23, 8, '03,04', '2', '4', false], [
            $row['sws'], $row['avg_time'], $row['age'], $row['ss'], $row['mnp'], $row['mxp'], $row['candidate'],
        ]);

        require_once PRIVATE_PATH . '/record_use.php';
        $this->assertSame([
            'ok' => true,
            'message' => 'The interaction with Catan with 0 people was recorded.',
            'artifact_id' => 10,
            'artifact_name' => 'Catan',
            'artifact_type' => 'board-game',
            'use_id' => 42,
            'use_date' => '2026-02-01',
            'new_use_by_date' => '2026-02-22',
            'most_recent_use_date' => '2026-02-01',
            'is_overdue' => true,
        ], record_use_ajax_payload([
            'artifact' => ['id' => 10, 'name' => 'Catan'], 'useDate' => '2026-02-01',
        ], 42, $queue->entry(10)));
    }

    public function test_an_absent_joined_type_stays_null_instead_of_using_legacy_type_text(): void
    {
        $this->db->query("UPDATE games SET type_id = 999, type = 'legacy type' WHERE id = 10");
        $this->assertNull($this->queue()->entry(10)['type']);
        $this->assertNull($this->queue()->entries()[0]['type']);
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
        $entry = $this->queue()->entries(['default_interval' => 30])[0];
        $this->assertSame('2026-02-21', $entry['use_by_date']);
        $this->assertSame(10.0, $entry['interval']);
        $this->assertSame(10.0, $this->queue()->entry(10)['interval']);
    }

    public function test_without_its_own_frequency_an_item_uses_the_pages_interval_or_the_owners_default(): void
    {
        $this->db->query('UPDATE games SET interaction_frequency_days = NULL WHERE id = 10');
        (new \Preferences($this->db, 1))->save(['default_use_interval' => 45]);
        $this->assertSame('2026-04-02', $this->queue()->entries(['default_interval' => 30])[0]['use_by_date']);
        $this->assertSame(30.0, $this->queue()->entries(['default_interval' => 30])[0]['interval']);
        $this->assertSame('2026-05-02', $this->queue()->entries()[0]['use_by_date']);
        $this->assertSame(45.0, $this->queue()->entries()[0]['interval']);
        $this->assertSame(45.0, $this->queue()->entry(10)['interval']);
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
        foreach (['1', 1, false] as $typeIds) {
            $this->assertSame([12, 10], $this->ids($queue->entries(['include_secondary_collection' => true, 'type_ids' => $typeIds])));
        }
        $this->assertSame([10], $this->ids($queue->entries(['type_ids' => ['1']])));
    }

    public function test_only_zero_or_null_removal_flags_allow_kept_or_secondary_items(): void
    {
        foreach (['NULL', '0', '1', '2', '-1'] as $removal) {
            $this->db->query("UPDATE games SET to_get_rid_of = $removal WHERE id IN (10, 12)");
            $this->assertSame(in_array($removal, ['NULL', '0']) ? [10] : [], $this->ids($this->queue()->entries()), $removal);
            $this->assertSame(in_array($removal, ['NULL', '0']) ? [12, 10] : [],
                $this->ids($this->queue()->entries(['include_secondary_collection' => true])), $removal);
        }
        $this->db->query('UPDATE games SET to_get_rid_of = 0, is_kept = 2, is_in_secondary_collection = -1 WHERE id IN (10, 12)');
        $this->assertSame([], $this->queue()->entries(['include_secondary_collection' => true]));
        $this->db->query('UPDATE games SET is_in_secondary_collection = 1 WHERE id = 12');
        $this->assertSame([12], $this->ids($this->queue()->entries(['include_secondary_collection' => true])));
    }

    public function test_format_flags_do_not_change_queue_membership(): void
    {
        foreach (['NULL', '0', '1', '2', '-1'] as $physical) {
            foreach (['NULL', '0', '1', '2', '-1'] as $digital) {
                $this->db->query("UPDATE games SET is_physical = $physical, is_digital = $digital");
                $this->assertSame([10], $this->ids($this->queue()->entries()), "$physical / $digital");
                $this->assertSame([12, 10], $this->ids($this->queue()->entries(['include_secondary_collection' => true])), "$physical / $digital");
            }
        }
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

    public function test_equal_use_by_and_last_use_dates_sort_by_item_id_including_undated_items(): void
    {
        $this->db->query("DELETE FROM uses");
        $this->db->query("UPDATE games SET Acq = NULL WHERE id = 10");
        $this->db->query("INSERT INTO games (id, user_id, Title, Acq) VALUES
            (14, 1, 'Z first by id', '2026-01-01'), (15, 1, 'A last by id', '2026-01-01'),
            (16, 1, 'A undated', NULL)");
        $this->assertSame([14, 15, 10, 16], $this->ids($this->queue()->entries()));
    }

    public function test_minimum_age_matches_the_prepared_mysql_comparison_on_nullable_integer_ages(): void
    {
        $this->db->query('UPDATE games SET is_kept = 1, to_get_rid_of = 0,
            Age = CASE id WHEN 10 THEN NULL WHEN 11 THEN -2 WHEN 12 THEN 0 ELSE 8 END');
        $this->db->query("INSERT INTO games (id, user_id, Title, Age) VALUES (14, 1, 'Age 9', 9), (15, 1, 'Age 10', 10)");
        foreach (['', 0, '0', null, 9, '9', 8.5, '8.5', -2, '-1.5', ' 9', '9 years',
            '8.5 years', '-1.5 years', '8e1years', '8.5e1years', 'nine', '00', '0.0', 0.0,
            false, true, '1e1', '+8.5', '  ', '-0', '.5'] as $minimumAge) {
            // The old prepared numeric comparison is the oracle for the
            // coercion moving out of SQL, including strict disabled values.
            $active = !in_array($minimumAge, ['', 0, '0', null], true);
            $stmt = $this->db->prepare('SELECT id FROM games WHERE user_id = 1'
                . ($active ? ' AND age >= ?' : '') . ' ORDER BY id');
            if ($active) {
                $value = (string) $minimumAge;
                $stmt->bind_param('s', $value);
            }
            $stmt->execute();
            $expected = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
            $stmt->close();
            $actual = $this->ids($this->queue()->entries(['minimum_age' => $minimumAge]));
            sort($actual);
            $this->assertSame($expected, $actual, var_export($minimumAge, true));
        }
    }

    public function test_minimum_age_preserves_decimals_and_legacy_malformed_text_coercion(): void
    {
        $this->db->query('UPDATE games SET Age = 8 WHERE id = 10');
        $this->assertSame([], $this->queue()->entries(['minimum_age' => '8.5']));
        // MySQL's prepared INT comparison retains decimals for numeric
        // values, but reads only the integer prefix of malformed text.
        $this->assertSame([10], $this->ids($this->queue()->entries(['minimum_age' => '8.5 years'])));
        $this->db->query('UPDATE games SET Age = 9 WHERE id = 10');
        $this->assertSame([10], $this->ids($this->queue()->entries(['minimum_age' => '8.5'])));
        $this->assertSame([10], $this->ids($this->queue()->entries(['minimum_age' => '8.5 years'])));
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

    public function test_sweet_spot_filter_reads_every_sweet_spot_spelling(): void
    {
        $this->db->query("UPDATE games SET ss = '3, 4' WHERE id = 10");
        $this->db->query("UPDATE games SET ss = '2-4' WHERE id = 12");
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, Acq, ss) VALUES
            (16, 1, 'Wavelength', 1, '2026-05-02', '06-8'),
            (17, 1, 'Codenames', 1, '2026-05-02', '4-6'),
            (18, 1, 'Patchwork', 1, '2026-05-02', '3,4')");
        $queue = $this->queue();
        $all = ['include_secondary_collection' => true];
        $sorted = function (array $entries) {
            $ids = $this->ids($entries);
            sort($ids);
            return $ids;
        };

        $this->assertSame([10, 12, 18], $sorted($queue->entries($all + ['sweet_spot' => '3'])));
        $this->assertSame([16], $sorted($queue->entries($all + ['sweet_spot' => '7'])));
        $this->assertSame([10, 12, 18], $sorted($queue->entries($all + ['sweet_spot' => ' 3 '])));
        $this->assertSame([16, 17], $sorted($queue->entries($all + ['sweet_spot' => 6])));
    }

    public function test_a_blank_zero_negative_or_non_numeric_sweet_spot_is_no_filter(): void
    {
        $this->db->query("UPDATE games SET ss = '2, 3' WHERE id = 10");
        $this->db->query("UPDATE games SET ss = '4' WHERE id = 12");
        $queue = $this->queue();
        $all = ['include_secondary_collection' => true];
        foreach (['', '0', 0, '-3', '+3', '03', 'three', '2.5', null] as $sweetSpot) {
            $this->assertSame([12, 10], $this->ids($queue->entries($all + ['sweet_spot' => $sweetSpot])), var_export($sweetSpot, true));
        }
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
        $this->db->query("UPDATE games SET is_kept = 0, is_in_secondary_collection = 1,
            to_get_rid_of = 2, snoozed_until = '2027-01-01' WHERE id = 10");
        $this->assertSame([], $queue->entries(['include_secondary_collection' => true, 'type_ids' => [1]]));
        $this->assertSame('Catan', $queue->entry(10)['Title']);
        $this->assertTrue($queue->entry(10)['is_snoozed']);
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

    public function test_today_defaults_to_the_app_day(): void
    {
        $this->assertSame(app_today(), (new UseByQueue($this->db, 1))->today());
    }
}
