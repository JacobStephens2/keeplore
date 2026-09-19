<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9 end-to-end (requires MySQL: set KEEPLORE_TEST_DB_HOST).
 *
 * Covers the acceptance criteria the unit suite cannot reach without a
 * database: participants attached to plays reads (brief 1), the
 * (use_id, player_id) uniqueness constraint rejecting duplicates
 * (brief 2), the player merge re-pointing rows and deleting the loser
 * (brief 3), and the type normalization migration (brief 4).
 */
final class IssueNineTest extends TestCase
{
    private ?\mysqli $db = null;

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
        $databaseName = 'keeplore_test_' . bin2hex(random_bytes(6));
        $this->db->query('CREATE DATABASE ' . $databaseName);
        $this->db->select_db($databaseName);
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        $this->runSql('ALTER TABLE players ADD COLUMN represents_user_id INT DEFAULT NULL');
        $this->runSql('ALTER TABLE users ADD COLUMN player_id INT DEFAULT NULL');
        $this->runSql(
            'CREATE TABLE uses_players (
                id INT PRIMARY KEY AUTO_INCREMENT,
                use_id INT NOT NULL,
                player_id INT NOT NULL,
                user_id INT NOT NULL,
                UNIQUE INDEX uniq_uses_players_use_player (use_id, player_id)
            ) ENGINE=InnoDB'
        );
        $this->runSql(
            'CREATE TABLE proposal_outcomes (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL
            ) ENGINE=InnoDB'
        );
        $this->runSql(
            'CREATE TABLE proposal_outcome_players (
                proposal_id INT UNSIGNED NOT NULL,
                player_id INT NOT NULL,
                PRIMARY KEY (proposal_id, player_id)
            ) ENGINE=InnoDB'
        );
        $this->runSql(
            'CREATE TABLE playgroup (
                ID INT PRIMARY KEY AUTO_INCREMENT,
                FullName INT NOT NULL,
                user_id INT NOT NULL
            ) ENGINE=InnoDB'
        );
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/use_participants.php';
        require_once PRIVATE_PATH . '/query_functions/player_queries.php';
        $GLOBALS['db'] = $this->db;
        $_SESSION['user_id'] = 1;
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $dbName = $this->db->query('SELECT DATABASE() AS d')->fetch_assoc()['d'];
            $this->db->query('DROP DATABASE ' . $dbName);
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

    // Brief 1: participants attach to plays reads.
    public function test_uses_carry_participants(): void
    {
        $this->db->query("INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1)");
        $uses = [['id' => 1, 'artifact_id' => 10]];
        $rows = find_participants_for_uses($this->db, [1], 1);
        $attached = attach_participants_to_uses($uses, $rows);
        $this->assertCount(1, $attached[0]['participants']);
        $this->assertSame('Sam', $attached[0]['participants'][0]['FirstName']);
    }

    // Brief 2: the uniqueness constraint rejects a duplicate link.
    public function test_duplicate_junction_row_is_rejected(): void
    {
        $this->db->query("INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1)");
        $this->expectException(\mysqli_sql_exception::class);
        $this->db->query("INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1)");
    }

    // Brief 3: merge re-points participations and deletes the loser.
    public function test_merge_players_repoints_and_deletes_loser(): void
    {
        $this->db->query("INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1), (1, 101, 1)");
        // Same pair pre-migration would be a duplicate; the constraint is
        // live here, so the shared play keeps one link after the merge.
        $result = merge_players(100, 101, 1);
        $this->assertTrue($result);
        $count = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM uses_players WHERE use_id = 1"
        )->fetch_assoc()['c'];
        $this->assertSame(1, $count);
        $survivor = $this->db->query("SELECT FirstName, LastName FROM players WHERE id = 100")->fetch_assoc();
        $this->assertSame('Sam', $survivor['FirstName']);
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM players WHERE id = 101"
        )->fetch_assoc()['c']);
    }

    // Brief 3: cross-account merges are refused.
    public function test_merge_players_refuses_cross_account(): void
    {
        $result = merge_players(100, 200, 1);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // Brief 4: the normalization migration canonicalizes type strings.
    public function test_normalize_migration_canonicalizes_type(): void
    {
        $this->db->query("UPDATE games SET type = 'table game' WHERE id = 10");
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/normalize-games-type.sql'));
        $type = $this->db->query("SELECT type FROM games WHERE id = 10")->fetch_assoc()['type'];
        $this->assertSame('board-game', $type);
    }
}
