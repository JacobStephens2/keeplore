<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * An owner's Type for BoardGameGeek items, used on Create Item and set on
 * Settings. Only one of the owner's own types counts; blank clears it, and
 * anything else is refused with its own error, leaving the setting.
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
        $this->assertSame(
            ['ok' => true, 'message' => 'Your type for BoardGameGeek items is now board-game.'],
            user_bgg_default_type_set($this->db, 1, '1')
        );

        $this->assertSame(['id' => 1, 'name' => 'board-game'], user_bgg_default_type($this->db, 1));
    }

    public function test_saving_the_type_already_set_says_nothing(): void
    {
        user_bgg_default_type_set($this->db, 1, '1');

        $this->assertSame(['ok' => true, 'message' => null], user_bgg_default_type_set($this->db, 1, '1'));
    }

    public function test_another_owners_type_is_refused_and_leaves_the_setting(): void
    {
        user_bgg_default_type_set($this->db, 1, '2');

        $this->assertSame(
            ['ok' => false, 'error' => 'That type is not one of yours.'],
            user_bgg_default_type_set($this->db, 1, '3')
        );
        $this->assertSame(['id' => 2, 'name' => 'film'], user_bgg_default_type($this->db, 1));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notATypeProvider')]
    public function test_input_that_is_not_a_type_is_refused_and_leaves_the_setting(string $raw): void
    {
        user_bgg_default_type_set($this->db, 1, '2');

        $this->assertSame(
            ['ok' => false, 'error' => 'That is not a type.'],
            user_bgg_default_type_set($this->db, 1, $raw)
        );
        $this->assertSame(['id' => 2, 'name' => 'film'], user_bgg_default_type($this->db, 1));
    }

    public static function notATypeProvider(): array
    {
        return [
            'words' => ['table game'],
            'zero' => ['0'],
            'negative' => ['-2'],
            'decimal' => ['1.5'],
            'trailing junk' => ['1abc'],
        ];
    }

    public function test_a_database_failure_is_reported_and_leaves_the_setting(): void
    {
        user_bgg_default_type_set($this->db, 1, '2');
        $this->db->query("CREATE TRIGGER refuse_user_updates BEFORE UPDATE ON users FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused'");

        $this->assertSame(
            ['ok' => false, 'error' => 'Your type for BoardGameGeek items could not be saved. Please try again.'],
            user_bgg_default_type_set($this->db, 1, '1')
        );
        $this->assertSame(['id' => 2, 'name' => 'film'], user_bgg_default_type($this->db, 1));
    }

    public function test_blank_clears_the_bgg_default(): void
    {
        user_bgg_default_type_set($this->db, 1, '1');

        $this->assertSame(
            ['ok' => true, 'message' => 'You no longer have a type for BoardGameGeek items.'],
            user_bgg_default_type_set($this->db, 1, ' ')
        );
        $this->assertNull(user_bgg_default_type($this->db, 1));
    }

    public function test_a_default_whose_type_now_belongs_elsewhere_reads_as_none(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 1');

        $this->assertNull(user_bgg_default_type($this->db, 1));
    }

    public function test_an_owners_types_are_listed_by_name(): void
    {
        $this->assertSame(['board-game' => 1, 'film' => 2], user_types($this->db, 1));
        $this->assertSame(['table game' => 3], user_types($this->db, 2));
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
