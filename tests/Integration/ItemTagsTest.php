<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Owner tags persist per user, round-trip on item reads, and never leak
 * across collections.
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
        require_once PRIVATE_PATH . '/item_tags.php';
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
        $GLOBALS['db'] = $this->db;
        $_SESSION['user_id'] = 1;
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

    private function tagsOn(int $artifactId, int $userId): array
    {
        $items = with_item_tags($this->db, [['id' => $artifactId]], $userId);
        return $items[0]['tags'];
    }

    public function test_owner_can_add_and_remove_tags_on_an_item(): void
    {
        $this->assertTrue(replace_item_tags($this->db, 10, 1, ['portable', 'beach-safe']));
        $this->assertSame(['beach-safe', 'portable'], $this->tagsOn(10, 1));

        $this->assertTrue(replace_item_tags($this->db, 10, 1, ['portable', 'party']));
        $this->assertSame(['party', 'portable'], $this->tagsOn(10, 1));
    }

    public function test_one_users_tags_never_appear_on_another_users_item(): void
    {
        replace_item_tags($this->db, 10, 1, ['beach-safe']);
        replace_item_tags($this->db, 20, 2, ['beach-safe', 'party']);

        $this->assertSame(['beach-safe'], $this->tagsOn(10, 1));
        $this->assertSame([], $this->tagsOn(10, 2));
        $this->assertSame(['beach-safe', 'party'], $this->tagsOn(20, 2));
        $this->assertSame([], $this->tagsOn(20, 1));
    }

    public function test_replace_refuses_to_tag_another_users_item(): void
    {
        $this->assertFalse(replace_item_tags($this->db, 20, 1, ['party']));
        $this->assertSame([], $this->tagsOn(20, 2));
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM item_tags')->fetch_assoc()['c']);
    }

    public function test_items_can_be_filtered_by_tag_without_crossing_users(): void
    {
        replace_item_tags($this->db, 10, 1, ['beach-safe']);
        replace_item_tags($this->db, 11, 1, ['party']);
        replace_item_tags($this->db, 20, 2, ['beach-safe']);

        $this->assertSame([10], artifact_ids_with_tag($this->db, 1, 'Beach-Safe'));
        $this->assertSame([20], artifact_ids_with_tag($this->db, 2, 'beach-safe'));
        $this->assertSame([], artifact_ids_with_tag($this->db, 1, 'two-player'));
    }

    public function test_collection_query_filters_by_tag_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        replace_item_tags($this->db, 10, 1, ['beach-safe']);
        replace_item_tags($this->db, 11, 1, ['party']);
        replace_item_tags($this->db, 20, 2, ['beach-safe']);

        $ids = [];
        $result = find_artifacts_by_user_id('yes', [], 90, '', 'beach-safe');
        while ($row = mysqli_fetch_assoc($result)) {
            $ids[] = (int) $row['id'];
        }
        $this->assertSame([10], $ids);
    }

    public function test_user_item_list_filters_by_tag_and_attaches_tags(): void
    {
        require_once PRIVATE_PATH . '/collection_list.php';
        replace_item_tags($this->db, 10, 1, ['beach-safe']);
        replace_item_tags($this->db, 11, 1, ['party']);

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
