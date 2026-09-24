<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #58: an agent key can answer "which games do I keep, with player
 * counts and play history" from the list endpoint, filter plays by person,
 * and look up the household's players without SSH.
 */
final class AgentCollectionReadTest extends TestCase
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
        $this->runSql(
            "ALTER TABLE games ADD COLUMN Wt VARCHAR(50) DEFAULT NULL, ADD COLUMN Yr DOUBLE DEFAULT NULL;
             ALTER TABLE players ADD COLUMN FullName VARCHAR(255) DEFAULT NULL,
               ADD COLUMN birth_year INT DEFAULT NULL, ADD COLUMN represents_user_id INT DEFAULT NULL;
             CREATE TABLE uses_players (
               id INT AUTO_INCREMENT PRIMARY KEY,
               use_id INT NOT NULL,
               player_id INT NOT NULL,
               user_id INT NOT NULL
             ) ENGINE=InnoDB;
             UPDATE games SET mnp = 3, mxp = 4, ss = '4', mnt = 60, mxt = 120, Wt = '2.3', Yr = 1995,
               is_physical = 1 WHERE id = 10;
             UPDATE players SET birth_year = 1990, represents_user_id = 1 WHERE id = 100;
             INSERT INTO uses (id, artifact_id, user_id, use_date) VALUES
               (2, 10, 1, '2026-03-05'), (3, 11, 1, '2026-01-10'), (4, 20, 2, '2026-04-01');
             INSERT INTO uses_players (use_id, player_id, user_id) VALUES
               (2, 100, 1), (2, 100, 1), (2, 101, 1), (3, 101, 1), (4, 200, 2);"
        );
        require_once PRIVATE_PATH . '/item_tags.php';
        require_once PRIVATE_PATH . '/kept_status.php';
        require_once PRIVATE_PATH . '/collection_list.php';
        require_once PRIVATE_PATH . '/use_participants.php';
        require_once PRIVATE_PATH . '/players_list.php';
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
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

    private function listItems(array $body, int $userId = 1): array
    {
        $request = parse_collection_list_request((object) $body);
        $this->assertSame([], $request['errors']);
        return list_collection_items($this->db, $userId, $request);
    }

    private function ids(array $rows): array
    {
        return array_map(fn ($row) => (int) $row['id'], $rows);
    }

    public function test_list_rows_carry_kept_type_and_format_flags_by_default(): void
    {
        $result = $this->listItems([]);
        $this->assertSame([12, 11, 10, 13], $this->ids($result['items']));
        $catan = $result['items'][2];
        $this->assertSame('Catan', $catan['Title']);
        $this->assertSame(1, $catan['type_id']);
        $this->assertSame('board-game', $catan['type']);
        $this->assertSame(1, $catan['is_kept']);
        $this->assertSame(1, $catan['is_physical']);
        $this->assertArrayHasKey('is_digital', $catan);
        $this->assertSame(0, $catan['is_in_secondary_collection']);
        $this->assertSame([], $catan['tags']);
        $this->assertArrayNotHasKey('MnP', $catan);
        $this->assertArrayNotHasKey('plays', $catan);
        $this->assertFalse($result['has_more']);
    }

    public function test_kept_filter_returns_only_kept_items_for_the_user(): void
    {
        $this->assertSame([11, 10], $this->ids($this->listItems(['kept' => true])['items']));
        $this->assertSame([12, 13], $this->ids($this->listItems(['kept' => false])['items']));
    }

    public function test_type_physical_and_secondary_filters_narrow_the_list(): void
    {
        $this->assertSame([12], $this->ids($this->listItems(['type_id' => [2]])['items']));
        $this->assertSame([11, 10, 13], $this->ids($this->listItems(['type_id' => 1])['items']));
        $this->assertSame([10], $this->ids($this->listItems(['physical' => true])['items']));
        $this->assertSame([12], $this->ids($this->listItems(['secondary_collection' => true])['items']));
    }

    public function test_collection_fields_and_uses_summary_answer_the_vacation_question_in_one_call(): void
    {
        $result = $this->listItems([
            'kept' => true,
            'type_id' => [1],
            'physical' => true,
            'fields' => 'collection',
            'include' => ['uses_summary'],
            'per_page' => 200,
        ]);
        $this->assertCount(1, $result['items']);
        $catan = $result['items'][0];
        $this->assertSame(3, $catan['MnP']);
        $this->assertSame(4, $catan['MxP']);
        $this->assertSame('4', $catan['SS']);
        $this->assertSame(60, $catan['MnT']);
        $this->assertSame(120, $catan['MxT']);
        $this->assertSame('2.3', $catan['Wt']);
        $this->assertEquals(1995, $catan['Yr']);
        $this->assertSame('2026-01-01', $catan['Acq']);
        $this->assertSame(2, $catan['plays']);
        $this->assertSame('2026-03-05', $catan['last_use']);
    }

    public function test_items_without_uses_report_zero_plays_and_null_last_use(): void
    {
        $rows = $this->listItems(['include' => 'uses_summary', 'type_id' => 2])['items'];
        $this->assertSame(0, $rows[0]['plays']);
        $this->assertNull($rows[0]['last_use']);
    }

    public function test_offset_pagination_reports_has_more(): void
    {
        $first = $this->listItems(['per_page' => 3]);
        $this->assertSame([12, 11, 10], $this->ids($first['items']));
        $this->assertTrue($first['has_more']);
        $second = $this->listItems(['per_page' => 3, 'page' => 2]);
        $this->assertSame([13], $this->ids($second['items']));
        $this->assertFalse($second['has_more']);
    }

    public function test_cursor_pagination_walks_by_id_and_keeps_filters(): void
    {
        $first = $this->listItems(['cursor' => null, 'per_page' => 1, 'kept' => true]);
        $this->assertSame([10], $this->ids($first['items']));
        $this->assertTrue($first['has_more']);
        $this->assertSame(10, $first['next_cursor']);
        $second = $this->listItems(['cursor' => 10, 'per_page' => 1, 'kept' => true]);
        $this->assertSame([11], $this->ids($second['items']));
        $this->assertFalse($second['has_more']);
        $this->assertNull($second['next_cursor']);
    }

    public function test_query_and_tag_filters_still_apply(): void
    {
        replace_item_tags($this->db, 10, 1, ['beach-safe']);
        $this->assertSame([10], $this->ids($this->listItems(['tag' => 'beach-safe'])['items']));
        $this->assertSame([11], $this->ids($this->listItems(['query' => 'Az'])['items']));
        $this->assertSame(['beach-safe'], $this->listItems(['tag' => 'beach-safe'])['items'][0]['tags']);
    }

    public function test_list_never_includes_another_users_items_or_uses(): void
    {
        $rows = $this->listItems(['include' => 'uses_summary'], 2)['items'];
        $this->assertSame([20], $this->ids($rows));
        $this->assertSame(1, $rows[0]['plays']);
    }

    public function test_uses_carry_deduplicated_player_ids_and_filter_by_player(): void
    {
        $uses = find_uses_with_participants($this->db, 1);
        $this->assertSame([2, 1, 3], array_map(fn ($u) => (int) $u['id'], $uses));
        $this->assertSame([101, 100], $uses[0]['players']);
        $this->assertCount(2, $uses[0]['participants']);
        $this->assertSame([], $uses[1]['players']);

        $byPlayer = find_uses_with_participants($this->db, 1, null, 101);
        $this->assertSame([2, 3], array_map(fn ($u) => (int) $u['id'], $byPlayer));

        $byPlayerAndItem = find_uses_with_participants($this->db, 1, 10, 101);
        $this->assertSame([2], array_map(fn ($u) => (int) $u['id'], $byPlayerAndItem));
    }

    public function test_uses_player_filter_cannot_reach_another_users_plays(): void
    {
        $this->assertSame([], find_uses_with_participants($this->db, 1, null, 200));
    }

    public function test_players_list_is_scoped_to_the_user(): void
    {
        $players = list_players_for_user($this->db, 1);
        $this->assertSame([101, 100], array_column($players, 'id'));
        $sam = $players[1];
        $this->assertSame('Sam Lee', $sam['name']);
        $this->assertSame(1990, $sam['birth_year']);
        $this->assertSame(1, $sam['represents_user_id']);
        $this->assertNull($players[0]['birth_year']);
        $this->assertSame([200], array_column(list_players_for_user($this->db, 2), 'id'));
    }
}
