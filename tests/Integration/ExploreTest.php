<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: the Explore module, candidate_items() and characteristic_items(),
 * each the owner's Items::list rows filtered and ordered for its page.
 * Owner 1 has board-game (1) and film (2); owner 2 has card game (3);
 * owner 8 has board-game (8).
 */
final class ExploreTest extends TestCase
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
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2), (8, 'board-game', 8)");
        $this->runSql('DELETE FROM uses');
        require_once PRIVATE_PATH . '/explore.php';
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

    /** Insert items as [id, user_id, Title, type_id, extra columns]. */
    private function items(array $items): void
    {
        foreach ($items as [$id, $userId, $title, $typeId, $columns]) {
            $columns = ['id' => $id, 'user_id' => $userId, 'Title' => $title, 'type_id' => $typeId] + $columns;
            $stmt = $this->db->prepare(
                'INSERT INTO games (`' . implode('`, `', array_keys($columns)) . '`)
                 VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            $stmt->bind_param(str_repeat('s', count($columns)), ...array_values($columns));
            $stmt->execute();
            $stmt->close();
        }
    }

    private function titles(array $rows): array
    {
        return array_column($rows, 'Title');
    }

    public function test_candidates_are_items_rows_with_last_use_and_type_name(): void
    {
        $this->items([[10, 1, 'Catan', 1, ['Candidate' => 'Sam', 'type' => 'stale name']]]);

        $row = candidate_items($this->db, 1, ['1'])[0];

        $this->assertSame('board-game', $row['type_name']);
        $this->assertArrayHasKey('last_use', $row);
        $this->assertSame([], $row['tags']);
    }

    public function test_candidates_leave_out_a_blank_or_0_candidate(): void
    {
        $this->items([
            [10, 1, 'Catan', 1, ['Candidate' => 'Sam']],
            [11, 1, 'Azul', 1, ['Candidate' => '0']],
            [12, 1, 'Brass', 1, ['Candidate' => '  ']],
            [13, 1, 'Carcassonne', 1, []],
            [20, 2, 'Bohnanza', 3, ['Candidate' => 'Sam']],
        ]);

        $this->assertSame(['Catan'], $this->titles(candidate_items($this->db, 1, ['1', '3'])));
    }

    public function test_candidates_last_use_counts_a_later_legacy_play_but_not_another_accounts_use(): void
    {
        $this->items([[10, 1, 'Catan', 1, ['Candidate' => 'Sam']]]);
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-01-01'), (10, 2, '2026-06-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-03-01')");

        $this->assertSame('2026-03-01', candidate_items($this->db, 1, ['1'])[0]['last_use']);
    }

    public function test_candidates_match_online_and_excluded_names_case_insensitively(): void
    {
        $this->items([
            [10, 1, 'Catan', 1, ['Candidate' => 'Sam']],
            [11, 1, 'Azul', 1, ['Candidate' => 'Jo ONLINE']],
            [12, 1, 'Brass', 1, ['Candidate' => 'Lee']],
        ]);

        $this->assertSame(['Azul'], $this->titles(candidate_items($this->db, 1, ['1'], ['online' => 'only'])));
        $this->assertSame(['Brass', 'Catan'], $this->titles(candidate_items($this->db, 1, ['1'], ['online' => 'hide'])));
        $this->assertSame(['Brass'], $this->titles(
            candidate_items($this->db, 1, ['1'], ['online' => 'hide', 'exclude_names' => ['SAM']])
        ));
    }

    public function test_candidates_order_by_type_name_then_candidate_then_title_ignoring_case(): void
    {
        $this->items([
            [10, 1, 'Zoo', 2, ['Candidate' => 'Sam']],
            [11, 1, 'beta', 1, ['Candidate' => 'sam']],
            [12, 1, 'Alpha', 1, ['Candidate' => 'Sam']],
            [13, 1, 'Gamma', 1, ['Candidate' => 'Ann']],
        ]);

        $this->assertSame(['Gamma', 'Alpha', 'beta', 'Zoo'], $this->titles(candidate_items($this->db, 1, ['1', '2'])));
    }

    public function test_characteristic_items_never_lists_another_owners_item(): void
    {
        $this->items([
            [10, 1, 'Catan', 1, ['SS' => '4']],
            [20, 2, 'Bohnanza', 3, ['SS' => '4']],
            [80, 8, 'Jacobs game', 8, ['SS' => '4']],
        ]);

        $this->assertSame(['Catan'], $this->titles(characteristic_items($this->db, 1, ['1', '3', '8'])));
    }

    public function test_characteristic_items_filter_by_type_id_and_an_empty_list_lists_nothing(): void
    {
        $this->items([
            [10, 1, 'Catan', 1, ['SS' => '4', 'type' => 'film']],
            [11, 1, 'Arrival', 2, ['SS' => '2', 'type' => 'board-game']],
        ]);

        $this->assertSame(['Catan'], $this->titles(characteristic_items($this->db, 1, ['1'])));
        $this->assertSame([], characteristic_items($this->db, 1, []));
    }

    public function test_characteristic_items_leave_out_a_blank_sweet_spot_and_kept_lists_only_kept(): void
    {
        $this->items([
            [10, 1, 'Catan', 1, ['SS' => '4', 'is_kept' => 1]],
            [11, 1, 'Azul', 1, ['SS' => '2', 'is_kept' => 0]],
            [12, 1, 'Brass', 1, ['SS' => '', 'is_kept' => 1]],
            [13, 1, 'Carcassonne', 1, ['SS' => null, 'is_kept' => 1]],
        ]);

        $this->assertSame(['Azul', 'Catan'], $this->titles(characteristic_items($this->db, 1, ['1'])));
        $this->assertSame(['Catan'], $this->titles(characteristic_items($this->db, 1, ['1'], ['kept' => true])));
    }

    public function test_characteristic_items_default_order(): void
    {
        $this->items([
            [10, 1, 'Rating 7.5', 1, ['SS' => '3', 'MxT' => 60, 'MnT' => 30, 'Age' => 10, 'FavCt' => 5, 'BGG_Rat' => '7.5']],
            [11, 1, 'Rating 10', 1, ['SS' => '3', 'MxT' => 60, 'MnT' => 30, 'Age' => 10, 'FavCt' => 5, 'BGG_Rat' => '10']],
            [12, 1, 'More favs', 1, ['SS' => '3', 'MxT' => 60, 'MnT' => 30, 'Age' => 10, 'FavCt' => 9, 'BGG_Rat' => '1']],
            [13, 1, 'Younger', 1, ['SS' => '3', 'MxT' => 60, 'MnT' => 30, 'Age' => 8, 'FavCt' => 0]],
            [14, 1, 'Shorter min', 1, ['SS' => '3', 'MxT' => 60, 'MnT' => 20, 'Age' => 99]],
            [15, 1, 'Shorter max', 1, ['SS' => '3', 'MxT' => 45, 'MnT' => 40, 'Age' => 99]],
            [16, 1, 'Smaller spot', 1, ['SS' => '2', 'MxT' => 999, 'MnT' => 999, 'Age' => 99]],
        ]);

        $this->assertSame(
            ['Smaller spot', 'Shorter max', 'Shorter min', 'Younger', 'More favs', 'Rating 10', 'Rating 7.5'],
            $this->titles(characteristic_items($this->db, 1, ['1']))
        );
    }

    public function test_characteristic_items_fav_count_order_puts_fav_count_first(): void
    {
        $this->items([
            [10, 1, 'Rating 7.5', 1, ['SS' => '3', 'FavCt' => 5, 'BGG_Rat' => '7.5']],
            [11, 1, 'Rating 10', 1, ['SS' => '3', 'FavCt' => 5, 'BGG_Rat' => '10']],
            [12, 1, 'Larger spot, most favs', 1, ['SS' => '5', 'FavCt' => 9]],
            [13, 1, 'Smaller spot', 1, ['SS' => '2', 'FavCt' => 5, 'BGG_Rat' => '1']],
        ]);

        $this->assertSame(
            ['Larger spot, most favs', 'Smaller spot', 'Rating 10', 'Rating 7.5'],
            $this->titles(characteristic_items($this->db, 1, ['1'], ['order' => 'fav_count']))
        );
    }
}
