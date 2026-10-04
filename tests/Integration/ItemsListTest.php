<?php

namespace Tests\Integration;

use InvalidArgumentException;
use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Items::list, the owner's Items in title order with their type name,
 * tags, last use and use count, narrowed by filters that combine with AND.
 * Last use is the later of the latest recorded Use and the latest legacy
 * play date. Another owner's Items and Uses never appear.
 */
final class ItemsListTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Items $items;

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
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, type, is_kept, is_in_secondary_collection,
                is_physical, is_digital, to_get_rid_of, Acq)
            VALUES
            (10, 1, 'Catan', 1, 'board-game', 1, 0, 1, NULL, 0, '2020-01-01'),
            (11, 1, 'Azul', 1, 'board-game', 1, 0, 0, 1, 1, '2021-01-01'),
            (12, 1, 'Arrival', 2, 'film', 0, 1, NULL, 1, 0, '2022-01-01'),
            (13, 1, 'Brass', NULL, 'legacy', 0, 0, 1, 0, 1, '2023-01-01'),
            (14, 1, 'catan', NULL, NULL, NULL, 0, NULL, NULL, 0, '2024-01-01'),
            (20, 2, 'Bohnanza', 3, 'card game', 1, 1, 1, 1, 1, '2025-01-01')");
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'family'), (1, 10, 'beach-safe'), (1, 12, 'family'), (2, 20, 'family')");
        $this->runSql('DELETE FROM uses');
        require_once PRIVATE_PATH . '/classes/Items.php';
        $this->items = new Items($this->db, 1);
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

    private function ids(array $filters = []): array
    {
        return array_map(fn (array $row) => (int) $row['id'], $this->items->list($filters));
    }

    private function row(int $id): array
    {
        return array_column($this->items->list(), null, 'id')[$id];
    }

    public function test_lists_only_the_owners_items_in_title_order_then_id(): void
    {
        $this->assertSame([12, 11, 13, 10, 14], $this->ids());
    }

    public function test_a_row_is_the_items_columns_with_its_type_name_and_tags(): void
    {
        $row = $this->row(10);
        $this->assertSame('Catan', $row['Title']);
        $this->assertSame('2020-01-01', $row['Acq']);
        $this->assertSame('board-game', $row['type']);
        $this->assertSame('board-game', $row['type_name']);
        $this->assertSame(['beach-safe', 'family'], $row['tags']);
        $this->assertNull($this->row(13)['type_name']);
        $this->assertSame([], $this->row(13)['tags']);
    }

    public function test_use_count_counts_recorded_uses_but_not_legacy_plays(): void
    {
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES
            (10, 1, '2026-01-01'), (10, 1, '2026-02-01'), (11, 1, '2026-01-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-03-01'), (12, 1, '2026-03-01')");
        $this->assertSame(2, $this->row(10)['use_count']);
        $this->assertSame(1, $this->row(11)['use_count']);
        $this->assertSame(0, $this->row(12)['use_count']);
    }

    public function test_last_use_from_uses_only(): void
    {
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-01-01'), (10, 1, '2026-02-01')");
        $this->assertSame('2026-02-01', $this->row(10)['last_use']);
    }

    public function test_last_use_from_legacy_plays_only(): void
    {
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2025-05-01'), (10, 1, '2025-04-01')");
        $this->assertSame('2025-05-01', $this->row(10)['last_use']);
    }

    public function test_last_use_is_the_later_of_uses_and_legacy_plays(): void
    {
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-02-01'), (11, 1, '2026-01-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-01-15'), (11, 1, '2026-03-01')");
        $this->assertSame('2026-02-01', $this->row(10)['last_use']);
        $this->assertSame('2026-03-01', $this->row(11)['last_use']);
    }

    public function test_last_use_is_null_without_uses_or_legacy_plays(): void
    {
        $this->assertNull($this->row(10)['last_use']);
        $this->assertSame(0, $this->row(10)['use_count']);
    }

    public function test_flag_filters_true_means_set_and_false_includes_a_null_column(): void
    {
        $this->assertSame([11, 10], $this->ids(['kept' => true]));
        $this->assertSame([12, 13, 14], $this->ids(['kept' => false]));
        $this->assertSame([12], $this->ids(['secondary_collection' => true]));
        $this->assertSame([11, 13, 10, 14], $this->ids(['secondary_collection' => false]));
        $this->assertSame([13, 10], $this->ids(['physical' => true]));
        $this->assertSame([12, 11, 14], $this->ids(['physical' => false]));
        $this->assertSame([12, 11], $this->ids(['digital' => true]));
        $this->assertSame([13, 10, 14], $this->ids(['digital' => false]));
        $this->assertSame([11, 13], $this->ids(['to_get_rid_of' => true]));
        $this->assertSame([12, 10, 14], $this->ids(['to_get_rid_of' => false]));
    }

    public function test_a_null_filter_is_no_filter(): void
    {
        $all = $this->ids();
        $this->assertSame($all, $this->ids([
            'kept' => null, 'secondary_collection' => null, 'physical' => null, 'digital' => null,
            'to_get_rid_of' => null, 'type_ids' => null, 'tag' => null, 'title' => null,
        ]));
        $this->assertSame($all, $this->ids(['tag' => '  ', 'title' => '']));
    }

    public function test_type_ids_null_lists_every_type_and_empty_lists_nothing(): void
    {
        $this->assertSame([12, 11, 13, 10, 14], $this->ids(['type_ids' => null]));
        $this->assertSame([], $this->ids(['type_ids' => []]));
        $this->assertSame([11, 10], $this->ids(['type_ids' => [1]]));
        $this->assertSame([12, 11, 10], $this->ids(['type_ids' => ['1', 2]]));
        $this->assertSame([], $this->ids(['type_ids' => [3]]));
    }

    public function test_tag_filter_is_normalized(): void
    {
        $this->assertSame([12, 10], $this->ids(['tag' => '  FAMILY ']));
        $this->assertSame([10], $this->ids(['tag' => 'beach-safe']));
        $this->assertSame([], $this->ids(['tag' => 'nothing']));
    }

    public function test_title_filter_matches_a_substring(): void
    {
        $this->assertSame([10, 14], $this->ids(['title' => 'atan']));
        $this->assertSame([12, 11, 13, 10, 14], $this->ids(['title' => 'a']));
        $this->assertSame([], $this->ids(['title' => 'Bohnanza']));
    }

    public function test_filters_combine_with_and(): void
    {
        $this->assertSame([10], $this->ids(['kept' => true, 'to_get_rid_of' => false]));
        $this->assertSame([12], $this->ids(['tag' => 'family', 'kept' => false]));
        $this->assertSame([11], $this->ids(['type_ids' => [1], 'digital' => true, 'title' => 'z']));
    }

    public function test_an_unknown_filter_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->items->list(['keept' => true]);
    }

    public function test_another_owners_items_and_uses_never_appear(): void
    {
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (20, 2, '2026-05-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (20, 2, '2026-05-02')");
        foreach ([[], ['kept' => true], ['tag' => 'family'], ['title' => 'Bohnanza'], ['type_ids' => [3]], ['to_get_rid_of' => true]] as $filters) {
            $this->assertNotContains(20, $this->ids($filters));
        }
        foreach ($this->items->list() as $row) {
            $this->assertNull($row['last_use']);
            $this->assertSame(0, $row['use_count']);
        }
        $theirs = (new Items($this->db, 2))->list();
        $this->assertSame([20], array_map(fn (array $row) => (int) $row['id'], $theirs));
        $this->assertSame('2026-05-02', $theirs[0]['last_use']);
        $this->assertSame(1, $theirs[0]['use_count']);
    }

    public function test_list_runs_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-02-01'), (10, 1, '2026-01-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2026-03-01')");
        $rows = $this->items->list(['to_get_rid_of' => true, 'tag' => '', 'type_ids' => [1, 2]]);
        $this->assertSame([11], array_map(fn (array $row) => (int) $row['id'], $rows));
        $this->assertSame('2026-03-01', $rows[0]['last_use']);
        $this->assertSame('board-game', $rows[0]['type_name']);
        $this->assertSame(2, $this->row(10)['use_count']);
    }
}
