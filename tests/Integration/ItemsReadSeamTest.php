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
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
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

    private function fetchIds($result): array
    {
        $ids = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $ids[] = (int) $row['id'];
        }
        sort($ids);
        return $ids;
    }

    public function test_kept_filters_agree_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->assertSame([10, 11], $this->fetchIds(find_artifacts_by_user_id('yes', [], 90)));
        $this->assertSame([12, 13], $this->fetchIds(find_artifacts_by_user_id('no', [], 90)));
        $this->assertSame([12], $this->fetchIds(find_artifacts_by_user_id('secondary_only', [], 90)));
    }

    public function test_to_get_rid_of_list_includes_is_kept_under_strict_group_by(): void
    {
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $result = find_artifacts_to_get_rid_of();
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        $this->assertCount(1, $rows);
        $this->assertSame(11, (int) $rows[0]['id']);
        $this->assertArrayHasKey('is_kept', $rows[0]);
        $this->assertTrue(artifact_is_kept($rows[0]));
    }
}
