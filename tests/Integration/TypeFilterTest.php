<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: type_filter(), which answers which of the owner's Types a page shows
 * and remembers the choice in the session. Owner 1 has board-game (1) and
 * film (2); owner 2 has card game (4) and table game (3).
 */
final class TypeFilterTest extends TestCase
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
        $this->db->query('ALTER TABLE types MODIFY id INT AUTO_INCREMENT, ADD COLUMN user_id INT NULL');
        $this->db->query('UPDATE types SET user_id = 1');
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (3, 'table game', 2), (4, 'card game', 2)");
        require_once PRIVATE_PATH . '/type_filter.php';
        require_once PRIVATE_PATH . '/query_functions/explore_queries.php';
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/query_functions/playgroup_queries.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_a_post_selects_the_ticked_types_that_are_the_owners_and_remembers_them(): void
    {
        $session = [];

        $filter = type_filter($this->db, 2, 'POST', ['type' => ['3' => '3', '1' => '1', '4' => '4']], $session);

        $this->assertSame(['card game' => 4, 'table game' => 3], $filter['types']);
        $this->assertSame(['4', '3'], $filter['selected']);
        $this->assertSame(['4', '3'], $session['type']);
    }

    public function test_a_post_with_nothing_ticked_selects_none_and_remembers_that(): void
    {
        $session = ['type' => ['3']];

        $filter = type_filter($this->db, 2, 'POST', ['csrf_token' => 'x'], $session);

        $this->assertSame([], $filter['selected']);
        $this->assertSame([], $session['type']);
    }

    public function test_a_get_uses_the_remembered_selection(): void
    {
        $session = ['type' => ['3']];

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['3'], $filter['selected']);
        $this->assertSame(['3'], $session['type']);
    }

    public function test_a_get_drops_a_remembered_type_that_has_since_been_deleted(): void
    {
        $session = ['type' => ['3', '4']];
        $this->db->query('DELETE FROM types WHERE id = 4');

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['3'], $filter['selected']);
    }

    public function test_a_get_selects_all_when_nothing_remembered_survives(): void
    {
        $session = ['type' => ['4']];
        $this->db->query('DELETE FROM types WHERE id = 4');

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['3'], $filter['selected']);
    }

    public function test_a_get_reads_a_legacy_name_to_id_map_as_its_ids(): void
    {
        $session = ['type' => ['table game' => 3]];

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['3'], $filter['selected']);
    }

    public function test_a_get_treats_the_legacy_1_as_nothing_remembered(): void
    {
        $session = ['type' => '1'];

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['4', '3'], $filter['selected']);
        $this->assertSame(['4', '3'], $session['type']);
    }

    public function test_a_get_with_nothing_remembered_selects_all_the_owners_types(): void
    {
        $session = [];

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['4', '3'], $filter['selected']);
    }

    public function test_a_get_never_selects_another_owners_remembered_type(): void
    {
        $session = ['type' => ['1', '2']];

        $filter = type_filter($this->db, 2, 'GET', [], $session);

        $this->assertSame(['4', '3'], $filter['selected']);
    }

    public function test_candidates_filtered_on_a_type_are_the_owners_items_of_that_type_id(): void
    {
        $this->db->query("UPDATE games SET Candidate = 'Sam', type = 'stale name' WHERE id IN (10, 12, 20)");

        $titles = fn (array $typeIds) => array_column(
            candidate_items($this->db, 1, $typeIds, [])->fetch_all(MYSQLI_ASSOC), 'Title'
        );

        $this->assertSame(['Catan'], $titles(['1']));
        $this->assertSame(['Arrival', 'Catan'], $titles(['1', '2']));
        $this->assertSame([], $titles([]));
    }

    public function test_choose_for_group_filtered_on_a_type_is_the_owners_items_of_that_type_id(): void
    {
        $this->db->query('CREATE TABLE playgroup (ID INT PRIMARY KEY AUTO_INCREMENT, FullName INT)');
        $this->db->query('ALTER TABLE players ADD COLUMN G INT, ADD COLUMN Priority INT');
        $this->db->query('ALTER TABLE responses ADD COLUMN Player INT, ADD COLUMN AversionDate DATE, ADD COLUMN PassDate DATE, ADD COLUMN RequestDate DATE');
        $this->db->query('ALTER TABLE games ADD COLUMN FavCt INT');
        $this->db->query("UPDATE games SET type = 'stale name'");
        $this->db->query('INSERT INTO playgroup (FullName) VALUES (100)');
        $this->db->query("INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES (10, 1, 100, '2026-02-01'), (12, 1, 100, '2026-02-02')");
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $GLOBALS['db'] = $this->db;
        $_SESSION = ['user_id' => 1];

        $titles = fn (array $typeIds) => array_column(
            choose_artifacts_for_group('false', $typeIds)->fetch_all(MYSQLI_ASSOC), 'title'
        );

        try {
            $this->assertSame(['Catan'], $titles(['1']));
            $this->assertEqualsCanonicalizing(['Arrival', 'Catan'], $titles(['1', '2']));
            $this->assertSame([], $titles([]));
        } finally {
            $_SESSION = [];
            unset($GLOBALS['db']);
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
}
