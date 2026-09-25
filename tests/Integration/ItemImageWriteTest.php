<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Create Item with a BGG cover URL and link must persist games.image_url and
 * games.bgg_url. Production 500ed on POST /artifacts/new for Quelf because
 * insert_artifact writes image_url and add-item-image-url.sql had not been
 * applied, so the fixture builds those columns only through the migrations.
 */
final class ItemImageWriteTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-image-url.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-url.sql'));

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

    private function runSql(string $sql): void
    {
        $this->db->multi_query($sql);
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    public function test_creating_an_item_with_a_bgg_cover_and_link_does_not_500(): void
    {
        $cover = 'https://cf.geekdo-images.com/SfNSwt9FWMx3FHM5ljzicQ__itemrep/img/1jsqDH3ag4F7k82kgg-j_6TOrj4=/fit-in/246x300/filters:strip_icc()/pic200936.jpg';
        $result = insert_artifact([
            'Title' => 'Quelf',
            'is_in_secondary_collection' => 0,
            'Acq' => '2026-09-20',
            'age' => 12,
            'Yr' => '2005',
            'type' => 44,
            'interaction_frequency_days' => '182.0',
            'is_kept' => '1',
            'to_get_rid_of' => '0',
            'Candidate' => '',
            'CandidateGroupDate' => '2026-09-20',
            'UsedRecUserCt' => '0',
            'Notes' => '',
            'MnT' => '60',
            'MxT' => '60',
            'MnP' => '3',
            'MxP' => '8',
            'SS' => '05,06',
            'is_digital' => null,
            'is_physical' => null,
            'image_url' => normalize_item_image_url($cover),
            'bgg_url' => 'https://boardgamegeek.com/boardgame/19370/quelf',
        ]);
        $this->assertTrue($result);

        $row = find_artifact_by_id((int) $this->db->insert_id);
        $this->assertSame('Quelf', $row['Title']);
        $this->assertSame($cover, $row['image_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/19370/quelf', $row['bgg_url']);
    }
}
