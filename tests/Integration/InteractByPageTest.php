<?php

namespace Tests\Integration;

use InteractByPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PRIVATE_PATH . '/interact_by.php';

final class InteractByPageTest extends TestCase
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
        $this->db->query('ALTER TABLE types ADD COLUMN user_id INT NULL');
        $this->db->query('UPDATE types SET user_id = 1');
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (3, 'book', 2)");
        $this->db->query('UPDATE games SET type_id = 3 WHERE id = 20');
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

    private function page(string $today = '2026-06-01', int $owner = 1): InteractByPage
    {
        return new InteractByPage($this->db, $owner, $today);
    }

    private function ids(array $answer): array
    {
        return array_column($answer['rows'], 'id');
    }

    public function test_a_get_returns_the_complete_owner_scoped_page(): void
    {
        $session = ['unrelated' => ['keep me']];
        $answer = $this->page()->read('GET', [], $session, false);

        $this->assertSame(['type_filter', 'filters', 'rows', 'table', 'total_overdue'], array_keys($answer));
        $this->assertSame(['types' => ['board-game' => 1, 'film' => 2], 'selected' => ['1', '2']], $answer['type_filter']);
        $this->assertSame([
            'sweetSpot' => '', 'minimumAge' => 0, 'shelfSort' => 'no',
            'showAttributes' => 'no', 'showInterval' => 'no', 'hideSnoozed' => 'yes', 'interval' => 90.0,
        ], $answer['filters']);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame('Catan', $answer['rows'][0]['title']);
        $this->assertSame('2026-07-31', $answer['rows'][0]['use_by_date']);
        $this->assertSame(0, $answer['total_overdue']);
        $this->assertSame([
            'columns' => ['name', 'use_by_date', 'record', 'type', 'get_rid_of', 'overdue', 'last_use', 'acq'],
            'order' => [[1, 'asc'], [6, 'asc'], [7, 'asc']],
        ], $answer['table']);
        $this->assertSame(['unrelated' => ['keep me'], 'hideSnoozed' => 'yes'], $session);
    }

    public function test_get_uses_surviving_remembered_types_without_rewriting_the_selection(): void
    {
        $this->db->query('UPDATE games SET is_kept = 1 WHERE id = 12');
        $session = ['type' => ['2', '3', '999'], 'unrelated' => 'keep'];
        $answer = $this->page()->read('GET', ['type' => ['1']], $session, false);

        $this->assertSame(['2'], $answer['type_filter']['selected']);
        $this->assertSame([12], $this->ids($answer));
        $this->assertSame(1, $answer['total_overdue']);
        $this->assertSame(['type' => ['2', '3', '999'], 'unrelated' => 'keep', 'hideSnoozed' => 'yes'], $session);

        $this->db->query('DELETE FROM types WHERE id = 2');
        $answer = $this->page()->read('GET', [], $session, false);
        $this->assertSame(['1'], $answer['type_filter']['selected']);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame(['2', '3', '999'], $session['type']);
    }

    public function test_post_remembers_only_owned_types_and_ignores_a_posted_owner(): void
    {
        $session = ['unrelated' => ['keep']];
        $answer = $this->page()->read('pOsT', ['type' => ['3', '2', '1', '999'], 'user_id' => 2], $session, false);
        $this->assertSame(['1', '2'], $answer['type_filter']['selected']);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame(['unrelated' => ['keep'], 'type' => ['1', '2'], 'hideSnoozed' => 'no'], $session);

        $answer = $this->page('2026-06-01', 2)->read('POST', ['type' => ['1', '3'], 'user_id' => 1], $session, false);
        $this->assertSame(['types' => ['book' => 3], 'selected' => ['3']], $answer['type_filter']);
        $this->assertSame([20], $this->ids($answer));
        $this->assertSame(1, $answer['total_overdue']);
    }

    public function test_an_empty_post_has_no_rows_but_the_next_get_falls_back_to_all_types(): void
    {
        $session = ['type' => ['1'], 'hideSnoozed' => 'yes', 'csrf_token' => 'keep'];
        $answer = $this->page()->read('POST', [], $session, false);
        $this->assertSame([], $answer['type_filter']['selected']);
        $this->assertSame([], $answer['rows']);
        $this->assertSame(0, $answer['total_overdue']);
        $this->assertSame(['type' => [], 'hideSnoozed' => 'no', 'csrf_token' => 'keep'], $session);
        $this->assertSame([[1, 'asc'], [6, 'asc'], [7, 'asc']], $answer['table']['order']);

        $answer = $this->page()->read('GET', [], $session, false);
        $this->assertSame(['1', '2'], $answer['type_filter']['selected']);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame([], $session['type']);
        $this->assertSame('no', $answer['filters']['hideSnoozed']);
    }

    public function test_get_ignores_post_filters_and_remembers_only_type_and_hide_snoozed(): void
    {
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05', interaction_frequency_days = NULL WHERE id = 10");
        $session = ['other' => 'keep'];
        $post = [
            'type' => ['1'], 'sweetSpot' => '99', 'minimumAge' => '99', 'shelfSort' => 'yes',
            'showAttributes' => 'yes', 'showInterval' => 'yes', 'interval' => '-1',
        ];
        $answer = $this->page()->read('POST', $post, $session, false);
        $this->assertSame([], $answer['rows']);
        $this->assertSame(['other' => 'keep', 'type' => ['1'], 'hideSnoozed' => 'no'], $session);

        $answer = $this->page()->read('GET', $post + ['hideSnoozed' => 'yes'], $session, false);
        $this->assertSame([
            'sweetSpot' => '', 'minimumAge' => 0, 'shelfSort' => 'no', 'showAttributes' => 'no',
            'showInterval' => 'no', 'hideSnoozed' => 'no', 'interval' => 90.0,
        ], $answer['filters']);
        $this->assertSame([10], $this->ids($answer));
        $this->assertTrue($answer['rows'][0]['is_snoozed']);
        $this->assertSame('2026-07-31', $answer['rows'][0]['use_by_date']);
        $this->assertSame(0, $answer['total_overdue']);

        $this->page()->read('POST', ['type' => ['1'], 'hideSnoozed' => 'yes'], $session, false);
        $answer = $this->page()->read('GET', [], $session, false);
        $this->assertSame('yes', $answer['filters']['hideSnoozed']);
        $this->assertSame([], $answer['rows']);
    }

    public static function nonYesValues(): array
    {
        return [['on'], ['YES'], [true], [1], ['1'], ['maybe'], [''], [null]];
    }

    #[DataProvider('nonYesValues')]
    public function test_only_exact_yes_enables_yes_no_filters($value): void
    {
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05' WHERE id = 10");
        $session = ['hideSnoozed' => $value];
        $answer = $this->page()->read('POST', [
            'type' => ['1', '2'], 'hideSnoozed' => $value, 'shelfSort' => $value,
            'showAttributes' => $value, 'showInterval' => $value,
        ], $session, false);
        foreach (['hideSnoozed', 'shelfSort', 'showAttributes', 'showInterval'] as $filter) {
            $this->assertSame('no', $answer['filters'][$filter]);
        }
        $this->assertSame([10], $this->ids($answer));
        $this->assertTrue($answer['rows'][0]['is_snoozed']);
        $this->assertCount(8, $answer['table']['columns']);
        $session['hideSnoozed'] = $value;
        $answer = $this->page()->read('GET', [], $session, false);
        // Null is treated as absent, preserving the GET default.
        $this->assertSame($value === null ? [] : [10], $this->ids($answer));
        $this->assertSame($value === null ? 'yes' : 'no', $session['hideSnoozed']);
    }

    public function test_combined_filters_select_the_visible_rows_and_their_overdue_count(): void
    {
        $this->db->query("UPDATE games SET ss = '03,04', Age = 10, interaction_frequency_days = NULL WHERE id IN (10, 12)");
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05' WHERE id = 10");
        $this->db->query('UPDATE games SET type_id = 1 WHERE id = 12');
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, Acq, ss, Age, interaction_frequency_days, snoozed_until) VALUES
            (14, 1, 'Due today', 1, '2026-05-02', '3', 12, NULL, '2026-06-01'),
            (15, 1, 'Too young', 1, '2026-01-01', '3', 8, NULL, NULL),
            (16, 1, 'Different Sweet spot', 1, '2026-01-01', '4', 12, NULL, NULL),
            (17, 1, 'Different Type', 2, '2026-01-01', '3', 12, NULL, NULL)");
        $post = ['type' => ['1'], 'sweetSpot' => '3', 'minimumAge' => '9', 'shelfSort' => 'yes', 'hideSnoozed' => 'yes', 'interval' => '30'];
        $session = [];
        $answer = $this->page()->read('POST', $post, $session, false);
        $this->assertSame('3', $answer['filters']['sweetSpot']);
        $this->assertSame('9', $answer['filters']['minimumAge']);
        $this->assertSame([12, 14], $this->ids($answer));
        $this->assertSame(['2026-01-31', '2026-06-01'], array_column($answer['rows'], 'use_by_date'));
        $this->assertSame([true, false], array_column($answer['rows'], 'overdue'));
        $this->assertSame(1, $answer['total_overdue']);

        foreach ([
            [['shelfSort' => 'no'], [14], 0],
            [['hideSnoozed' => 'no'], [12, 10, 14], 2],
            [['sweetSpot' => '4'], [12, 16], 2],
            [['minimumAge' => '13'], [], 0],
            [['type' => ['2']], [17], 1],
            [['interval' => '200'], [12, 14], 0],
        ] as [$change, $ids, $overdue]) {
            $answer = $this->page()->read('POST', array_replace($post, $change), $session, false);
            $this->assertSame($ids, $this->ids($answer), json_encode($change));
            $this->assertSame($overdue, $answer['total_overdue'], json_encode($change));
        }
    }

    public static function viewIntervals(): array
    {
        return [
            'fraction' => ['30.5', 30.5, '2026-04-03', [12, 10]],
            'zero' => ['0', 0, '2026-02-01', [12, 10]],
            'negative' => ['-2', -2, '2026-01-28', [12, 10]],
            'blank' => ['', 45.25, '2026-05-02', [12, 10]],
            'nonnumeric' => ['soon', 45.25, '2026-05-02', [12, 10]],
            'null' => [null, 45.25, '2026-05-02', [12, 10]],
            'negative fraction' => ['-30.5', -30.5, '2025-12-02', [10, 12]],
        ];
    }

    #[DataProvider('viewIntervals')]
    public function test_view_intervals_do_not_change_preferences_items_or_uses($input, $interval, string $due, array $ids): void
    {
        $this->db->query('ALTER TABLE games MODIFY interaction_frequency_days DECIMAL(8,2)');
        $this->db->query('UPDATE users SET default_use_interval = 45.25 WHERE id = 1');
        $this->db->query('UPDATE users SET default_use_interval = 7 WHERE id = 2');
        $this->db->query('UPDATE games SET interaction_frequency_days = NULL WHERE id = 10');
        $this->db->query('UPDATE games SET is_kept = 1, interaction_frequency_days = 10.5 WHERE id = 12');
        $preferences = (new \Preferences($this->db, 1))->get();
        $items = (new \Items($this->db, 1))->list();
        // These snapshots observe the explicit persistence contract of a page read.
        $uses = $this->db->query('SELECT * FROM uses ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $session = ['keep' => true];

        $answer = $this->page()->read('POST', ['type' => ['1', '2'], 'interval' => $input], $session, false);
        $this->assertSame($interval, $answer['filters']['interval']);
        $this->assertSame($ids, $this->ids($answer));
        $rows = array_column($answer['rows'], null, 'id');
        $this->assertSame($due, $rows[10]['use_by_date']);
        $this->assertSame((float) $interval, $rows[10]['interval']);
        $this->assertSame('2026-01-11', $rows[12]['use_by_date']);
        $this->assertSame(10.5, $rows[12]['interval']);
        $this->assertSame(2, $answer['total_overdue']);
        $this->assertSame($preferences, (new \Preferences($this->db, 1))->get());
        $this->assertSame($items, (new \Items($this->db, 1))->list());
        $this->assertSame($uses, $this->db->query('SELECT * FROM uses ORDER BY id')->fetch_all(MYSQLI_ASSOC));
        $this->assertSame(['keep' => true, 'type' => ['1', '2'], 'hideSnoozed' => 'no'], $session);

        $answer = $this->page()->read('GET', ['interval' => '-2'], $session, false);
        $this->assertSame(45.25, $answer['filters']['interval']);
        $this->assertSame('2026-05-02', array_column($answer['rows'], null, 'id')[10]['use_by_date']);
    }

    public function test_minimum_age_is_passed_unchanged_to_the_queue_including_malformed_decimals(): void
    {
        $this->db->query('UPDATE games SET Age = 8 WHERE id = 10');
        $session = [];
        foreach ([['8.5', []], ['8.5 years', [10]], [8, [10]], [0, [10]], ['', [10]]] as [$age, $ids]) {
            $answer = $this->page()->read('POST', ['type' => ['1'], 'minimumAge' => $age], $session, false);
            $this->assertSame($age, $answer['filters']['minimumAge']);
            $this->assertSame($ids, $this->ids($answer));
        }
    }

    public static function itemSchemas(): array
    {
        return ['legacy casing' => [false], 'canonical casing' => [true]];
    }

    #[DataProvider('itemSchemas')]
    public function test_rows_preserve_exact_values_and_blank_null_distinctions_in_both_item_schemas(bool $canonical): void
    {
        if ($canonical) {
            $this->db->query('DROP TABLE games');
            preg_match('/CREATE TABLE IF NOT EXISTS games \(.*?\) ENGINE=[^;]*;/s',
                file_get_contents(PROJECT_PATH . '/database/local-schema.sql'), $match);
            $this->runSql($match[0]);
        } else {
            $this->db->query('DELETE FROM games');
            $this->db->query('ALTER TABLE games MODIFY interaction_frequency_days DECIMAL(8,2)');
        }
        $this->db->query("INSERT INTO games (id, user_id, Title, Acq, interaction_frequency_days, type_id, type,
                is_kept, is_in_secondary_collection, to_get_rid_of, mnp, mxp, mnt, mxt, Candidate, UsedRecUserCt, ss, Age)
            VALUES (10, 1, 'Catan', NULL, 10.50, 1, 'legacy type', 1, 0, 0, 2, 4, NULL, 45, ' 0 ', '007', '03,04', 8),
                (14, 1, 'Undated', NULL, NULL, 2, NULL, 1, 0, 0, NULL, NULL, NULL, NULL, 'Ann at home', NULL, '', 0)");
        $this->db->query("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (1, 10, 'private tag')");
        $session = [];
        $answer = $this->page()->read('GET', [], $session, false);
        $this->assertSame([
            [
                'id' => 10, 'title' => 'Catan', 'type' => 'board-game', 'use_by_date' => '2026-02-22',
                'overdue' => true, 'last_use' => '2026-02-01', 'acq' => '', 'interval' => 10.5,
                'is_snoozed' => false, 'snoozed_until' => '', 'sws' => 3, 'avg_time' => 23,
                'age' => 8, 'ss' => '03,04', 'mnp' => '2', 'mxp' => '4', 'candidate' => false,
            ],
            [
                'id' => 14, 'title' => 'Undated', 'type' => 'film', 'use_by_date' => '',
                'overdue' => false, 'last_use' => null, 'acq' => '', 'interval' => 90.0,
                'is_snoozed' => false, 'snoozed_until' => '', 'sws' => '', 'avg_time' => 0,
                'age' => '', 'ss' => '', 'mnp' => '', 'mxp' => '', 'candidate' => true,
            ],
        ], $answer['rows']);
        $this->assertSame(1, $answer['total_overdue']);
    }

    public function test_queue_order_and_overdue_count_use_only_returned_rows(): void
    {
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, Acq) VALUES
            (14, 1, 'Never used', 1, '2026-05-02'),
            (15, 1, 'Undated', 1, NULL),
            (16, 1, 'Earlier', 1, '2025-01-01')");
        $session = [];
        $answer = $this->page('2026-07-31')->read('GET', [], $session, false);
        $this->assertSame([16, 14, 10, 15], $this->ids($answer));
        $this->assertSame(['2025-04-01', '2026-07-31', '2026-07-31', ''], array_column($answer['rows'], 'use_by_date'));
        $this->assertSame([true, false, false, false], array_column($answer['rows'], 'overdue'));
        $this->assertSame(1, $answer['total_overdue']);
        $answer = $this->page('2026-08-01')->read('GET', [], $session, false);
        $this->assertSame(3, $answer['total_overdue']);
    }

    public function test_snoozed_state_expires_on_the_pinned_app_day(): void
    {
        $this->db->query("UPDATE games SET snoozed_until = '2026-06-05', interaction_frequency_days = 10 WHERE id = 10");
        $session = [];
        $answer = $this->page('2026-06-04')->read('GET', [], $session, false);
        $this->assertSame([], $answer['rows']);
        $this->assertSame(0, $answer['total_overdue']);
        $answer = $this->page('2026-06-04')->read('POST', ['type' => ['1']], $session, false);
        $this->assertSame([10], $this->ids($answer));
        $this->assertTrue($answer['rows'][0]['is_snoozed']);
        $this->assertSame('2026-06-05', $answer['rows'][0]['snoozed_until']);
        $this->assertSame(1, $answer['total_overdue']);
        $session['hideSnoozed'] = 'yes';
        foreach (['2026-06-05', '2026-06-06'] as $day) {
            $answer = $this->page($day)->read('GET', [], $session, false);
            $this->assertSame([10], $this->ids($answer));
            $this->assertFalse($answer['rows'][0]['is_snoozed']);
            $this->assertSame('2026-02-21', $answer['rows'][0]['use_by_date']);
            $this->assertSame(1, $answer['total_overdue']);
        }
    }

    public function test_omitting_the_day_uses_the_app_day(): void
    {
        $today = app_today();
        $this->db->query('DELETE FROM uses');
        $this->db->query("UPDATE games SET Acq = '$today', interaction_frequency_days = 0, snoozed_until = '$today' WHERE id = 10");
        $session = [];
        $answer = (new InteractByPage($this->db, 1))->read('GET', [], $session, false);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame($today, $answer['rows'][0]['use_by_date']);
        $this->assertFalse($answer['rows'][0]['is_snoozed']);
        $this->assertSame(0, $answer['total_overdue']);
    }

    public static function tableLayouts(): array
    {
        $layouts = [
            'owner default' => [false, false,
                ['name', 'use_by_date', 'record', 'type', 'get_rid_of', 'overdue', 'last_use', 'acq'],
                [[1, 'asc'], [6, 'asc'], [7, 'asc']], [[1, 'asc'], [6, 'asc'], [7, 'asc']]],
            'guest default' => [true, false,
                ['name', 'use_by_date', 'type', 'overdue', 'last_use', 'acq'],
                [[1, 'asc'], [4, 'asc'], [5, 'asc']], [[1, 'asc'], [4, 'asc'], [5, 'asc']]],
            'owner attributes' => [false, true,
                ['name', 'use_by_date', 'record', 'type', 'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate', 'get_rid_of', 'overdue', 'last_use', 'acq'],
                [[1, 'asc'], [5, 'asc'], [6, 'asc']],
                [[3, 'asc'], [4, 'asc'], [5, 'asc'], [6, 'asc'], [7, 'asc'], [8, 'asc'], [9, 'asc'], [13, 'desc'], [10, 'desc']]],
            'guest attributes' => [true, true,
                ['name', 'use_by_date', 'type', 'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate', 'overdue', 'last_use', 'acq'],
                [[1, 'asc'], [4, 'asc'], [5, 'asc']],
                [[2, 'asc'], [3, 'asc'], [4, 'asc'], [5, 'asc'], [6, 'asc'], [7, 'asc'], [8, 'asc'], [11, 'desc'], [9, 'desc']]],
        ];
        $cases = [];
        foreach ($layouts as $name => [$guest, $attributes, $columns, $defaultOrder, $shelfOrder]) {
            foreach ([false, true] as $shelf) {
                foreach ([false, true] as $interval) {
                    $cases[$name . " shelf=$shelf interval=$interval"] = [
                        $guest, $attributes, $shelf, $interval,
                        $interval ? [...$columns, 'interval'] : $columns,
                        $shelf ? $shelfOrder : $defaultOrder,
                    ];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('tableLayouts')]
    public function test_every_table_layout_has_exact_columns_and_order_indices(bool $guest, bool $attributes, bool $shelf, bool $interval, array $columns, array $order): void
    {
        $session = [];
        $answer = $this->page()->read('POST', [
            'type' => ['1'], 'showAttributes' => $attributes ? 'yes' : 'no',
            'shelfSort' => $shelf ? 'yes' : 'no', 'showInterval' => $interval ? 'yes' : 'no',
        ], $session, $guest);
        $this->assertSame([10], $this->ids($answer));
        $this->assertSame(['columns' => $columns, 'order' => $order], $answer['table']);
    }

    public static function storageFailures(): array
    {
        return [
            'Type read fails first' => ['types', ['other' => 'keep', 'type' => ['2'], 'hideSnoozed' => 'yes']],
            'Preferences read fails before Hide snoozed' => ['users', ['other' => 'keep', 'type' => ['1'], 'hideSnoozed' => 'yes']],
            'queue read fails after both session changes' => ['uses', ['other' => 'keep', 'type' => ['1'], 'hideSnoozed' => 'no']],
        ];
    }

    #[DataProvider('storageFailures')]
    public function test_storage_failures_propagate_after_the_existing_session_mutations(string $missingTable, array $expectedSession): void
    {
        $this->db->query('DROP TABLE ' . $missingTable);
        $session = ['other' => 'keep', 'type' => ['2'], 'hideSnoozed' => 'yes'];
        try {
            $this->page()->read('POST', ['type' => ['1']], $session, false);
            $this->fail('Storage failure must propagate instead of returning an empty page.');
        } catch (\mysqli_sql_exception $error) {
            $this->assertStringContainsString($missingTable, $error->getMessage());
            $this->assertSame($expectedSession, $session);
        }
    }
}
