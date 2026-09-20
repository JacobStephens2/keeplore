<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Saving an item with a blank publication year must not 500. Production
 * stores `games.Yr` as DOUBLE under STRICT_TRANS_TABLES, and the edit
 * form posts Yr as '' when the year field is empty (item 4440).
 */
final class ItemYearWriteTest extends TestCase
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
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->db->query("CREATE TABLE types (id INT PRIMARY KEY, objectType VARCHAR(100)) ENGINE=InnoDB");
        $this->db->query("CREATE TABLE games (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            Title VARCHAR(255) NOT NULL,
            is_kept TINYINT DEFAULT 1,
            Acq DATE DEFAULT NULL,
            Candidate VARCHAR(255) DEFAULT NULL,
            UsedRecUserCt VARCHAR(50) DEFAULT NULL,
            type_id INT DEFAULT NULL,
            type VARCHAR(100) DEFAULT NULL,
            SS VARCHAR(255) DEFAULT NULL,
            Notes TEXT,
            CandidateGroupDate DATE DEFAULT NULL,
            MnT INT DEFAULT NULL,
            MxT INT DEFAULT NULL,
            Age INT DEFAULT NULL,
            Yr DOUBLE DEFAULT NULL,
            is_in_secondary_collection TINYINT DEFAULT 0,
            MnP INT DEFAULT NULL,
            MxP INT DEFAULT NULL,
            interaction_frequency_days DECIMAL(8,2) DEFAULT NULL,
            to_get_rid_of TINYINT DEFAULT 0,
            is_digital TINYINT DEFAULT NULL,
            is_physical TINYINT DEFAULT NULL
        ) ENGINE=InnoDB");
        $this->db->query("INSERT INTO types VALUES (44, 'other')");
        $this->db->query("INSERT INTO games (id, user_id, Title, is_kept, Acq, type_id, type, MnT, MxT, MnP, MxP, interaction_frequency_days, to_get_rid_of, UsedRecUserCt)
            VALUES (4440, 1, 'Twisted Fish', 1, '2026-09-20', 44, 'other', 30, 30, 3, 6, 182.00, 0, '0')");

        require_once PRIVATE_PATH . '/kept_status.php';
        require_once PRIVATE_PATH . '/cache.php';
        require_once PRIVATE_PATH . '/database.php';
        require_once PRIVATE_PATH . '/query_functions/utility_queries.php';
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
        $GLOBALS['db'] = $this->db;
        $GLOBALS['cache'] = new \Cache();
        $_SESSION['user_id'] = 1;
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function editPayload(array $changes = []): array
    {
        return array_replace([
            'id' => 4440,
            'Title' => 'Twisted Fish',
            'is_in_secondary_collection' => 0,
            'Acq' => '2026-04-11',
            'age' => 0,
            'Yr' => '',
            'type' => 44,
            'interaction_frequency_days' => '182',
            'is_kept' => '1',
            'to_get_rid_of' => '0',
            'Candidate' => '',
            'CandidateGroupDate' => '2026-09-20',
            'UsedRecUserCt' => '0',
            'Notes' => '',
            'MnT' => '30',
            'MxT' => '30',
            'MnP' => '3',
            'MxP' => '6',
            'SS' => '',
            'is_digital' => null,
            'is_physical' => null,
        ], $changes);
    }

    public function test_saving_a_blank_year_and_a_new_tracking_date_does_not_500(): void
    {
        $result = update_artifact($this->editPayload());
        $this->assertTrue($result);

        $row = find_artifact_by_id(4440);
        $this->assertSame('2026-04-11', $row['Acq']);
        $this->assertNull($row['Yr']);
    }

    public function test_creating_an_item_with_a_blank_year_does_not_500(): void
    {
        $payload = $this->editPayload();
        unset($payload['id']);
        $payload['Title'] = 'New blank-year item';

        $result = insert_artifact($payload);
        $this->assertTrue($result);

        $row = find_artifact_by_id((int) $this->db->insert_id);
        $this->assertSame('New blank-year item', $row['Title']);
        $this->assertNull($row['Yr']);
    }
}
