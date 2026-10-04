<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: items_list_payload, the Items page's rows. It reads the owner's
 * Items through Items::list, maps the page's kept, type and tag choices
 * onto list's filters, and orders rows newest acquisition first, then kept
 * first, then id. Recent Interaction is the Item's last use.
 */
final class ItemsPagePayloadTest extends TestCase
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
        $this->runSql('DROP TABLE games, types');
        $this->runSql($this->schemaTable('types') . $this->schemaTable('games'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, type, is_kept, is_in_secondary_collection,
                Acq, interaction_frequency_days, SS, Age)
            VALUES
            (10, 1, 'Catan', 1, 'board-game', 1, 0, '2020-01-01', NULL, '03,04', 10),
            (11, 1, 'Azul', 1, 'board-game', 1, 0, '2021-01-01', NULL, '02', 8),
            (12, 1, 'Arrival', 2, 'film', 0, 1, '2022-01-01', NULL, NULL, NULL),
            (13, 1, 'Brass', NULL, 'legacy', 1, 0, '2022-01-01', 30, '03', 14),
            (15, 1, 'Hanabi', 1, 'board-game', 1, 0, '2022-01-01', NULL, NULL, NULL),
            (14, 1, 'Coup', 1, 'board-game', NULL, 0, NULL, NULL, NULL, NULL),
            (20, 2, 'Bohnanza', 3, 'card game', 1, 1, '2025-01-01', NULL, '03', 8)");
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'beach-safe'), (1, 12, 'family'), (2, 20, 'beach-safe')");
        $this->runSql('DELETE FROM uses');
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES
            (10, 1, '2026-01-01'), (10, 1, '2026-02-01'), (13, 1, '2026-01-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2026-03-01'), (10, 1, '2025-12-01')");
        require_once PRIVATE_PATH . '/classes/Items.php';
        require_once PRIVATE_PATH . '/items_list.php';
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

    private function schemaTable(string $table): string
    {
        $schema = file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=[^;]*;/s', $schema, $match);
        return $match[0];
    }

    private function payload(array $filters = []): array
    {
        return items_list_payload($this->db, $filters + [
            'kept' => 'allkeptandnot',
            'type' => [],
            'interval' => 90,
            'players' => null,
            'age' => null,
            'ageUnknown' => false,
            'showAttributes' => 'no',
            'tagFilter' => '',
        ], 1, '2026-06-01');
    }

    private function ids(array $filters = []): array
    {
        return array_column($this->payload($filters), 'id');
    }

    private function row(int $id): array
    {
        return array_column($this->payload(), null, 'id')[$id];
    }

    public function test_rows_are_newest_acquisition_first_then_kept_first_then_id(): void
    {
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids());
    }

    public function test_kept_choice_maps_onto_the_kept_and_secondary_filters(): void
    {
        $this->assertSame([13, 15, 11, 10], $this->ids(['kept' => 'yes']));
        $this->assertSame([12, 14], $this->ids(['kept' => 'no']));
        $this->assertSame([12], $this->ids(['kept' => 'secondary_only']));
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['kept' => 'allkeptandnot']));
    }

    public function test_type_list_selects_those_types_and_an_empty_list_selects_every_type(): void
    {
        $this->assertSame([15, 11, 10, 14], $this->ids(['type' => ['1']]));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['type' => ['board-game' => '1', 'film' => '2']]));
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['type' => []]));
    }

    public function test_blank_type_entries_are_dropped_and_only_blanks_lists_nothing(): void
    {
        $this->assertSame([12], $this->ids(['type' => ['', '2']]));
        $this->assertSame([], $this->ids(['type' => ['']]));
    }

    public function test_tag_filter_lists_the_owners_tagged_items(): void
    {
        $this->assertSame([10], $this->ids(['tagFilter' => 'Beach-Safe']));
    }

    public function test_players_and_age_filters_still_narrow_the_rows(): void
    {
        $this->assertSame([13, 10], $this->ids(['players' => 3]));
        $this->assertSame([11, 10], $this->ids(['age' => 10]));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['age' => 10, 'ageUnknown' => true]));
    }

    public function test_recent_interaction_is_the_last_use_and_use_by_follows_it(): void
    {
        $catan = $this->row(10);
        $this->assertSame('2026-02-01', $catan['most_recent_use']);
        $this->assertSame('2026-07-31', $catan['use_by']);
        $this->assertSame('board-game', $catan['type']);
        $this->assertSame(['beach-safe'], $catan['tags']);
    }

    public function test_an_item_with_only_a_legacy_play_date_uses_it(): void
    {
        $azul = $this->row(11);
        $this->assertSame('2026-03-01', $azul['most_recent_use']);
        $this->assertSame('2026-08-28', $azul['use_by']);
    }

    public function test_an_unused_item_counts_from_acquisition(): void
    {
        $arrival = $this->row(12);
        $this->assertSame('', $arrival['most_recent_use']);
        $this->assertSame('2022-04-01', $arrival['use_by']);
        $this->assertFalse($arrival['use_by_overdue'], 'An item not kept is never overdue.');
    }

    public function test_the_items_own_frequency_replaces_the_page_interval(): void
    {
        $brass = $this->row(13);
        $this->assertSame('2026-01-01', $brass['most_recent_use']);
        $this->assertSame('2026-03-02', $brass['use_by']);
        $this->assertTrue($brass['use_by_overdue']);
        $this->assertSame('', $brass['type'], 'A legacy type string without a type id is no type.');
    }

    public function test_the_page_interval_is_the_default_for_items_without_their_own(): void
    {
        $rows = array_column(items_list_payload($this->db, [
            'kept' => 'allkeptandnot', 'type' => [], 'interval' => 10, 'players' => null,
            'age' => null, 'ageUnknown' => false, 'showAttributes' => 'no', 'tagFilter' => '',
        ], 1, '2026-06-01'), null, 'id');
        $this->assertSame('2026-02-21', $rows[10]['use_by']);
        $this->assertSame('2026-03-02', $rows[13]['use_by']);
    }
}
