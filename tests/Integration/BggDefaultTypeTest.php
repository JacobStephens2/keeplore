<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Types;

/**
 * Seam: Types::bggDefault() and Types::setBggDefault(), the owner's Type
 * for BoardGameGeek items, used on Create Item and set on Settings. Only one
 * of the owner's own types counts; blank clears it, and anything else is
 * refused with its own exception, leaving the setting.
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
        require_once PRIVATE_PATH . '/classes/Types.php';
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
        $this->assertNull($this->types()->bggDefault());
    }

    public function test_setting_one_of_your_types_makes_it_the_bgg_default(): void
    {
        $this->assertTrue($this->types()->setBggDefault('1'));

        $this->assertSame(['id' => 1, 'name' => 'board-game'], $this->idAndName());
        $this->assertSame($this->types()->find(1), $this->types()->bggDefault());
    }

    public function test_saving_the_type_already_set_changes_nothing(): void
    {
        $this->types()->setBggDefault('1');

        $this->assertFalse($this->types()->setBggDefault('1'));
        $this->assertSame(1, $this->types()->bggDefault()['id']);
    }

    public function test_another_owners_type_is_refused_and_leaves_the_setting(): void
    {
        $this->types()->setBggDefault('2');

        $this->assertRefused(\OutOfBoundsException::class, 'That type is not one of yours.', '3');
        $this->assertSame(['id' => 2, 'name' => 'film'], $this->idAndName());
    }

    public function test_an_id_that_names_no_type_is_refused_as_not_yours(): void
    {
        $this->assertRefused(\OutOfBoundsException::class, 'That type is not one of yours.', '999');
        $this->assertNull($this->types()->bggDefault());
    }

    public function test_one_of_two_same_named_types_can_be_set(): void
    {
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (4, 'film', 1)");

        $this->assertTrue($this->types()->setBggDefault('4'));
        $this->assertSame(['id' => 4, 'name' => 'film'], $this->idAndName());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notATypeProvider')]
    public function test_input_that_is_not_a_type_is_refused_and_leaves_the_setting(string $raw): void
    {
        $this->types()->setBggDefault('2');

        $this->assertRefused(\InvalidArgumentException::class, 'That is not a type.', $raw);
        $this->assertSame(['id' => 2, 'name' => 'film'], $this->idAndName());
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

    public function test_a_database_failure_propagates_and_leaves_the_setting(): void
    {
        $this->types()->setBggDefault('2');
        $this->db->query("CREATE TRIGGER refuse_user_updates BEFORE UPDATE ON users FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused'");

        try {
            $this->types()->setBggDefault('1');
            $this->fail('A database failure should propagate.');
        } catch (\mysqli_sql_exception) {
        }
        $this->assertSame(['id' => 2, 'name' => 'film'], $this->idAndName());
    }

    public function test_blank_clears_the_bgg_default(): void
    {
        $this->types()->setBggDefault('1');

        $this->assertTrue($this->types()->setBggDefault(' '));
        $this->assertNull($this->types()->bggDefault());
    }

    public function test_blank_when_none_is_set_changes_nothing(): void
    {
        $this->assertFalse($this->types()->setBggDefault(''));
    }

    public function test_blank_clears_a_default_whose_type_now_belongs_elsewhere(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 1');

        $this->assertTrue($this->types()->setBggDefault(''));
        $stored = $this->db->query('SELECT bgg_default_type_id FROM users WHERE id = 1')->fetch_row()[0];
        $this->assertNull($stored);
    }

    public function test_a_default_whose_type_now_belongs_elsewhere_reads_as_none(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 1');

        $this->assertNull($this->types()->bggDefault());
    }

    public function test_an_owners_types_are_listed_by_name(): void
    {
        $this->assertSame(['board-game' => 1, 'film' => 2], array_column((new Types($this->db, 1))->all(), 'id', 'name'));
        $this->assertSame(['table game' => 3], array_column((new Types($this->db, 2))->all(), 'id', 'name'));
    }

    private function types(): Types
    {
        return new Types($this->db, 1);
    }

    /** Owner 1's Type for BoardGameGeek items as id and name, or null. */
    private function idAndName(): ?array
    {
        $type = $this->types()->bggDefault();
        return $type === null ? null : ['id' => $type['id'], 'name' => $type['name']];
    }

    private function assertRefused(string $exception, string $message, string $raw): void
    {
        try {
            $this->types()->setBggDefault($raw);
            $this->fail("Setting '$raw' should throw $exception.");
        } catch (\InvalidArgumentException | \OutOfBoundsException $error) {
            $this->assertInstanceOf($exception, $error);
            $this->assertSame($message, $error->getMessage());
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
