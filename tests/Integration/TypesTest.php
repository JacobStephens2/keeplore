<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Types;

/**
 * Seam: the Types interface, the owner's own categories for items. Owner 1
 * has board-game (1) and film (2); owner 2 has table game (3).
 */
final class TypesTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Types $types;

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
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (3, 'table game', 2)");
        // The fixture's items carry no cached type name; give them the one Items writes.
        $this->db->query('UPDATE games JOIN types ON types.id = games.type_id SET games.type = types.objectType');
        $this->db->query("UPDATE games SET type_id = 3, type = 'table game' WHERE user_id = 2");
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-default-type.sql'));
        require_once PRIVATE_PATH . '/classes/Types.php';
        $this->types = new Types($this->db, 1);
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

    public function test_all_lists_the_owners_types_in_name_order_with_kept_and_not_kept_counts(): void
    {
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (4, 'Album', 1)");

        $this->assertSame([
            ['id' => 4, 'name' => 'Album', 'kept_count' => 0, 'not_kept_count' => 0],
            ['id' => 1, 'name' => 'board-game', 'kept_count' => 2, 'not_kept_count' => 1],
            ['id' => 2, 'name' => 'film', 'kept_count' => 0, 'not_kept_count' => 1],
        ], $this->types->all());
    }

    public function test_find_returns_the_owners_type_with_its_counts(): void
    {
        $this->assertSame(
            ['id' => 2, 'name' => 'film', 'kept_count' => 0, 'not_kept_count' => 1],
            $this->types->find(2)
        );
    }

    public function test_another_owners_type_reads_as_absent(): void
    {
        $this->assertNull($this->types->find(3));
        $this->assertNull($this->types->find(999));
        $this->assertNotContains(3, array_column($this->types->all(), 'id'));
    }

    public function test_create_trims_the_name_and_returns_the_new_types_id(): void
    {
        $id = $this->types->create('  book ');

        $this->assertSame(['id' => $id, 'name' => 'book', 'kept_count' => 0, 'not_kept_count' => 0], $this->types->find($id));
    }

    public function test_the_same_name_as_another_owners_type_is_allowed(): void
    {
        $id = $this->types->create('Table Game');

        $this->assertSame('Table Game', $this->types->find($id)['name']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedNameProvider')]
    public function test_create_refuses_a_bad_name_and_writes_nothing(string $name, string $message): void
    {
        try {
            $this->types->create($name);
            $this->fail('A bad name was accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame($message, $error->getMessage());
        }
        $this->assertSame(3, $this->typeCount());
    }

    public static function refusedNameProvider(): array
    {
        return [
            'blank' => [' ', 'Please enter a type name.'],
            'over 100 characters' => [str_repeat('a', 101), 'A type name can be at most 100 characters.'],
            'a duplicate in another case' => [' FILM ', 'You already have a type with this name.'],
        ];
    }

    public function test_a_name_of_exactly_100_characters_is_allowed(): void
    {
        $id = $this->types->create(str_repeat('é', 100));

        $this->assertSame(str_repeat('é', 100), $this->types->find($id)['name']);
    }

    public function test_rename_updates_the_type_and_every_one_of_the_owners_items_type_name(): void
    {
        $this->types->rename(1, ' tabletop ');

        $this->assertSame('tabletop', $this->types->find(1)['name']);
        $this->assertSame(
            [['10', 'tabletop'], ['11', 'tabletop'], ['12', 'film'], ['13', 'tabletop'], ['20', 'table game']],
            $this->db->query('SELECT id, type FROM games ORDER BY id')->fetch_all()
        );
    }

    public function test_rename_leaves_another_owners_items_with_the_type_alone(): void
    {
        // Owner 2's item points at owner 1's type, as legacy rows can.
        $this->db->query("UPDATE games SET type_id = 1, type = 'board-game' WHERE id = 20");

        $this->types->rename(1, 'tabletop');

        $this->assertSame('board-game', $this->db->query('SELECT type FROM games WHERE id = 20')->fetch_row()[0]);
    }

    public function test_rename_to_its_own_name_in_another_case_is_allowed(): void
    {
        $this->types->rename(2, 'Film');

        $this->assertSame('Film', $this->types->find(2)['name']);
        $this->assertSame('Film', $this->db->query('SELECT type FROM games WHERE id = 12')->fetch_row()[0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedNameProvider')]
    public function test_rename_refuses_a_bad_name_and_writes_nothing(string $name, string $message): void
    {
        try {
            $this->types->rename(1, $name);
            $this->fail('A bad name was accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame($message, $error->getMessage());
        }
        $this->assertSame('board-game', $this->types->find(1)['name']);
        $this->assertSame('board-game', $this->db->query('SELECT type FROM games WHERE id = 10')->fetch_row()[0]);
    }

    public function test_renaming_another_owners_type_throws_and_writes_nothing(): void
    {
        try {
            $this->types->rename(3, 'mine now');
            $this->fail('Another owner\'s type was renamed.');
        } catch (\OutOfBoundsException) {
        }
        $this->assertSame('table game', $this->db->query('SELECT objectType FROM types WHERE id = 3')->fetch_row()[0]);
        $this->assertSame('table game', $this->db->query('SELECT type FROM games WHERE id = 20')->fetch_row()[0]);
    }

    public function test_delete_moves_the_owners_items_to_the_destination_type(): void
    {
        $this->types->delete(1, 2);

        $this->assertNull($this->types->find(1));
        $this->assertSame(
            [['10', '2', 'film'], ['11', '2', 'film'], ['12', '2', 'film'], ['13', '2', 'film'], ['20', '3', 'table game']],
            $this->items()
        );
        $this->assertSame(['kept_count' => 2, 'not_kept_count' => 2], array_intersect_key(
            $this->types->find(2), ['kept_count' => 0, 'not_kept_count' => 0]
        ));
    }

    public function test_delete_into_no_type_leaves_the_owners_items_without_one(): void
    {
        // Owner 2's item points at owner 1's type, as legacy rows can.
        $this->db->query("UPDATE games SET type_id = 1, type = 'board-game' WHERE id = 20");

        $this->types->delete(1, null);

        $this->assertNull($this->types->find(1));
        $this->assertSame(
            [['10', null, null], ['11', null, null], ['12', '2', 'film'], ['13', null, null], ['20', '1', 'board-game']],
            $this->items()
        );
    }

    public function test_the_bgg_default_type_follows_the_items_to_the_destination(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 1 WHERE id = 1');
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 2');

        $this->types->delete(1, 2);

        $this->assertSame([['1', '2'], ['2', '3']], $this->bggDefaults());
    }

    public function test_the_bgg_default_type_clears_when_the_items_are_left_without_a_type(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 1 WHERE id = 1');

        $this->types->delete(1, null);

        $this->assertSame([['1', null], ['2', null]], $this->bggDefaults());
    }

    public function test_a_bgg_default_on_another_type_stays_when_a_type_is_deleted(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 2 WHERE id = 1');

        $this->types->delete(1, null);

        $this->assertSame([['1', '2'], ['2', null]], $this->bggDefaults());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedDestinationProvider')]
    public function test_delete_refuses_a_destination_that_is_not_another_of_the_owners_types_and_writes_nothing(int $destination): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 1 WHERE id = 1');
        $items = $this->items();

        try {
            $this->types->delete(1, $destination);
            $this->fail('A bad destination was accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Please choose another of your types to move the items to.', $error->getMessage());
        }
        $this->assertSame('board-game', $this->types->find(1)['name']);
        $this->assertSame($items, $this->items());
        $this->assertSame([['1', '1'], ['2', null]], $this->bggDefaults());
    }

    public static function refusedDestinationProvider(): array
    {
        return [
            'the type itself' => [1],
            'another owner\'s type' => [3],
            'an id that names no type' => [999],
        ];
    }

    public function test_deleting_another_owners_type_throws_and_writes_nothing(): void
    {
        $this->db->query('UPDATE users SET bgg_default_type_id = 3 WHERE id = 2');
        $items = $this->items();

        try {
            $this->types->delete(3, null);
            $this->fail('Another owner\'s type was deleted.');
        } catch (\OutOfBoundsException) {
        }
        $this->assertSame(3, $this->typeCount());
        $this->assertSame($items, $this->items());
        $this->assertSame([['1', null], ['2', '3']], $this->bggDefaults());
    }

    public function test_get_types_lists_the_key_users_types_as_id_and_type(): void
    {
        require_once PRIVATE_PATH . '/types_api.php';

        [$status, $fields] = list_types_over_api($this->db, (object) ['authenticated' => true, 'auth_type' => 'agent_key', 'user_id' => 1]);

        $this->assertSame(200, $status);
        $this->assertSame(['types' => [['id' => 1, 'type' => 'board-game'], ['id' => 2, 'type' => 'film']]], $fields);
    }

    public function test_get_types_refuses_the_master_key(): void
    {
        require_once PRIVATE_PATH . '/types_api.php';

        [$status, $fields] = list_types_over_api($this->db, (object) ['authenticated' => true, 'auth_type' => 'api_key']);

        $this->assertSame(400, $status);
        $this->assertSame(['message' => 'types.php requires a user-scoped key.'], $fields);
    }

    /** Every item's id, type id and cached type name, by id. */
    private function items(): array
    {
        return $this->db->query('SELECT id, type_id, type FROM games ORDER BY id')->fetch_all();
    }

    private function bggDefaults(): array
    {
        return $this->db->query('SELECT id, bgg_default_type_id FROM users ORDER BY id')->fetch_all();
    }

    private function typeCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM types')->fetch_row()[0];
    }
}
