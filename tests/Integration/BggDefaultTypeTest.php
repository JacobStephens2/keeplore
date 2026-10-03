<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * An owner's type for items filled from BoardGameGeek on Create Item, set on
 * Settings. Only one of the owner's own types counts; blank clears it.
 */
final class BggDefaultTypeTest extends TestCase
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
        $this->db->query('ALTER TABLE types ADD COLUMN user_id INT NULL');
        // Types 1-2 belong to user 1; type 3 to user 2.
        $this->db->query("UPDATE types SET user_id = 1");
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (3, 'table game', 2)");
        $migration = file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-default-type.sql');
        $this->runSql($migration);
        // Rerunning the migration must be harmless.
        $this->runSql($migration);
        require_once PRIVATE_PATH . '/item_types.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_an_owner_has_no_bgg_default_type_until_one_is_set(): void
    {
        $this->assertNull(user_bgg_default_type($this->db, 1));
    }

    public function test_setting_one_of_your_types_makes_it_the_bgg_default(): void
    {
        $this->assertTrue(user_bgg_default_type_set($this->db, 1, '1'));

        $this->assertSame(['id' => 1, 'name' => 'board-game'], user_bgg_default_type($this->db, 1));
    }

    public function test_another_owners_type_is_refused_and_leaves_the_setting(): void
    {
        user_bgg_default_type_set($this->db, 1, '2');

        $this->assertFalse(user_bgg_default_type_set($this->db, 1, '3'));
        $this->assertSame(['id' => 2, 'name' => 'film'], user_bgg_default_type($this->db, 1));
    }

    public function test_blank_clears_the_bgg_default(): void
    {
        user_bgg_default_type_set($this->db, 1, '1');

        $this->assertTrue(user_bgg_default_type_set($this->db, 1, ''));
        $this->assertNull(user_bgg_default_type($this->db, 1));
    }

    public function test_a_default_whose_type_now_belongs_elsewhere_reads_as_none(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 1');

        $this->assertNull(user_bgg_default_type($this->db, 1));
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
