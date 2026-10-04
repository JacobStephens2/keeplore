<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The Items read seam must survive production's strict sql_mode
 * (ONLY_FULL_GROUP_BY): every nonaggregated selected column has to be
 * grouped or functionally dependent, or /artifacts/ returns a 500.
 */
final class ItemsReadSeamTest extends TestCase
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
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/kept_status.php';
        require_once PRIVATE_PATH . '/classes/Items.php';
        require_once PRIVATE_PATH . '/items_list.php';
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-url.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
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

    private function payload(string $kept): array
    {
        return items_list_payload($this->db, [
            'kept' => $kept,
            'type' => [],
            'interval' => 90,
            'players' => null,
            'age' => null,
            'ageUnknown' => false,
            'showAttributes' => 'no',
            'tagFilter' => '',
        ], 1);
    }

    private function payloadIds(string $kept): array
    {
        $ids = array_column($this->payload($kept), 'id');
        sort($ids);
        return $ids;
    }

    public function test_kept_filters_agree_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->assertSame([10, 11], $this->payloadIds('yes'));
        $this->assertSame([12, 13], $this->payloadIds('no'));
        $this->assertSame([12], $this->payloadIds('secondary_only'));
    }

    public function test_items_page_rows_carry_the_overall_bgg_rating(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->db->query("UPDATE games SET BGG_Rat = '7.09' WHERE id = 10");
        $rows = array_column($this->payload('yes'), null, 'id');
        $this->assertSame('7.09', $rows[10]['bgg_average']);
    }

    public function test_to_get_rid_of_list_includes_is_kept_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->db->query("INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2026-03-01')");
        $rows = (new \Items($this->db, 1))->list(['to_get_rid_of' => true]);
        $this->assertCount(1, $rows);
        $this->assertSame(11, (int) $rows[0]['id']);
        $this->assertArrayHasKey('is_kept', $rows[0]);
        $this->assertTrue(artifact_is_kept($rows[0]));
        $this->assertSame('2026-03-01', $rows[0]['last_use']);
        $this->assertSame('board-game', $rows[0]['type_name']);
    }
}
