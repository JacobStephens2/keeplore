<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: Items. Owner tags persist per user through update, round-trip on
 * find and list, filter the list, and never leak across collections.
 */
final class ItemTagsTest extends TestCase
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
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/classes/Items.php';
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

    private function items(int $userId): \Items
    {
        return new \Items($this->db, $userId);
    }

    public function test_owner_can_add_and_remove_tags_on_an_item(): void
    {
        $this->items(1)->update(10, ['tags' => ['portable', 'beach-safe']]);
        $this->assertSame(['beach-safe', 'portable'], $this->items(1)->find(10)['tags']);

        $this->items(1)->update(10, ['tags' => 'Portable, party']);
        $this->assertSame(['party', 'portable'], $this->items(1)->find(10)['tags']);
    }

    public function test_one_users_tags_never_appear_on_another_users_item(): void
    {
        $this->items(1)->update(10, ['tags' => ['beach-safe']]);
        $this->items(2)->update(20, ['tags' => ['beach-safe', 'party']]);

        $this->assertSame(['beach-safe'], $this->items(1)->find(10)['tags']);
        $this->assertSame(['beach-safe', 'party'], $this->items(2)->find(20)['tags']);
        $this->assertSame([[]], array_column($this->items(1)->list(['title' => 'Azul']), 'tags'));
    }

    public function test_an_owner_cannot_tag_another_users_item(): void
    {
        try {
            $this->items(1)->update(20, ['tags' => ['party']]);
            $this->fail('Tagging another user\'s item must be refused.');
        } catch (\OutOfBoundsException $notFound) {
        }

        $this->assertSame([], $this->items(2)->find(20)['tags']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM item_tags')->fetch_assoc()['c']);
    }

    public function test_items_can_be_filtered_by_tag_without_crossing_users(): void
    {
        $this->items(1)->update(10, ['tags' => ['beach-safe']]);
        $this->items(1)->update(11, ['tags' => ['party']]);
        $this->items(2)->update(20, ['tags' => ['beach-safe']]);

        $ids = fn (array $rows) => array_map(fn (array $row) => (int) $row['id'], $rows);
        $this->assertSame([10], $ids($this->items(1)->list(['tag' => 'Beach-Safe'])));
        $this->assertSame([20], $ids($this->items(2)->list(['tag' => 'beach-safe'])));
        $this->assertSame([], $ids($this->items(1)->list(['tag' => 'two-player'])));
    }

    public function test_item_list_filters_by_tag_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->items(1)->update(10, ['tags' => ['beach-safe']]);
        $this->items(1)->update(11, ['tags' => ['party']]);
        $this->items(2)->update(20, ['tags' => ['beach-safe']]);

        $rows = $this->items(1)->list(['kept' => true, 'tag' => 'beach-safe']);
        $this->assertSame([10], array_map(fn (array $row) => (int) $row['id'], $rows));
    }

    public function test_user_item_list_filters_by_tag_and_attaches_tags(): void
    {
        require_once PRIVATE_PATH . '/collection_list.php';
        $this->items(1)->update(10, ['tags' => ['beach-safe']]);
        $this->items(1)->update(11, ['tags' => ['party']]);

        $listed = list_collection_items(
            $this->db,
            1,
            parse_collection_list_request((object) ['tag' => 'beach-safe'])
        )['items'];

        $this->assertCount(1, $listed);
        $this->assertSame(10, (int) $listed[0]['id']);
        $this->assertSame(['beach-safe'], $listed[0]['tags']);
    }
}
