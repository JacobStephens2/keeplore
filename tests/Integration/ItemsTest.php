<?php

namespace Tests\Integration;

use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Items, the owner's Items. Another owner's Item is never found and
 * never deleted, and deleting an Item removes its tags and its Event plan
 * entries with it.
 */
final class ItemsTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-events.sql'));
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'beach-safe'), (1, 10, 'family'), (1, 11, 'family'), (2, 20, 'mine')");
        $this->runSql("INSERT INTO events (id, user_id, name) VALUES (1, 1, 'Beach week'), (2, 2, 'Game night')");
        $this->runSql('INSERT INTO event_items (event_id, artifact_id) VALUES (1, 10), (1, 11), (2, 20)');
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

    private function column(string $sql): array
    {
        return array_map('intval', array_column($this->db->query($sql)->fetch_all(MYSQLI_NUM), 0));
    }

    private function tagsOf(int $itemId): array
    {
        return array_column(
            $this->db->query("SELECT tag FROM item_tags WHERE artifact_id = $itemId ORDER BY tag")->fetch_all(MYSQLI_NUM),
            0
        );
    }

    private function plannedOn(int $itemId): array
    {
        return $this->column("SELECT event_id FROM event_items WHERE artifact_id = $itemId ORDER BY event_id");
    }

    public function test_find_returns_the_owners_item_with_its_type_name(): void
    {
        $item = $this->items->find(10);

        $this->assertSame(10, (int) $item['id']);
        $this->assertSame(1, (int) $item['user_id']);
        $this->assertSame('Catan', $item['Title']);
        $this->assertSame('board-game', $item['type_name']);
    }

    public function test_find_returns_null_for_another_owners_item_or_a_missing_id(): void
    {
        $this->assertNull($this->items->find(20));
        $this->assertNull($this->items->find(999));
    }

    public function test_delete_removes_the_item_its_tags_and_its_event_plan_entries(): void
    {
        $this->items->delete(10);

        $this->assertNull($this->items->find(10));
        $this->assertSame([], $this->tagsOf(10));
        $this->assertSame([], $this->plannedOn(10));
    }

    public function test_delete_leaves_the_owners_other_items_alone(): void
    {
        $this->items->delete(10);

        $this->assertSame('Azul', $this->items->find(11)['Title']);
        $this->assertSame(['family'], $this->tagsOf(11));
        $this->assertSame([1], $this->plannedOn(11));
    }

    public function test_deleting_another_owners_item_is_not_found_and_changes_nothing(): void
    {
        try {
            $this->items->delete(20);
            $this->fail('Another owner\'s Item must not be deleted.');
        } catch (\OutOfBoundsException $expected) {
        }

        $this->assertSame('Private item', (new Items($this->db, 2))->find(20)['Title']);
        $this->assertSame(['mine'], $this->tagsOf(20));
        $this->assertSame([2], $this->plannedOn(20));
    }

    public function test_deleting_a_missing_item_is_not_found(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->items->delete(999);
    }

    public function test_a_failed_delete_leaves_the_item_its_tags_and_its_plans(): void
    {
        // The Item row's delete fails after its tags and plans are gone; the
        // transaction must bring them back.
        $this->runSql('CREATE TRIGGER games_no_delete BEFORE DELETE ON games FOR EACH ROW
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'no delete\'');

        try {
            $this->items->delete(10);
            $this->fail('The delete must fail.');
        } catch (\mysqli_sql_exception $expected) {
        }

        $this->assertSame('Catan', $this->items->find(10)['Title']);
        $this->assertSame(['beach-safe', 'family'], $this->tagsOf(10));
        $this->assertSame([1], $this->plannedOn(10));
    }
}
