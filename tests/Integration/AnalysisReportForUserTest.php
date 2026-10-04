<?php

namespace Tests\Integration;

use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seam: analysis_report_for_user(), the Analysis adapter. It reads the
 * owner's items, uses and people through Items, Uses and People, so an
 * item's Last use is Items' one rule (legacy plays included), another
 * owner's rows never appear, and the person marked as the owner is not
 * company.
 */
final class AnalysisReportForUserTest extends TestCase
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
        $this->runSql('DROP TABLE games, types');
        $this->runSql($this->schemaTable('types') . $this->schemaTable('games') . $this->schemaTable('uses_players'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql('ALTER TABLE players
            ADD COLUMN G VARCHAR(10) DEFAULT NULL,
            ADD COLUMN birth_year INT DEFAULT NULL,
            ADD COLUMN represents_user_id INT DEFAULT NULL');
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES (1, 'board-game', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, is_kept, to_get_rid_of, Acq) VALUES
            (10, 1, 'Catan', 1, 1, 0, '2020-01-01'),
            (11, 1, 'Azul', NULL, 1, 0, '2021-01-01'),
            (20, 2, 'Bohnanza', 3, 1, 0, '2019-01-01')");
        $this->runSql("UPDATE players SET represents_user_id = 1 WHERE id = 101");
        $this->runSql("DELETE FROM uses;
            INSERT INTO uses (id, artifact_id, user_id, use_date, note) VALUES
                (1, 10, 1, '2026-03-01', 'Home'),
                (2, 11, 1, '2026-09-01', 'Home'),
                (3, 20, 2, '2026-09-20', 'Elsewhere');
            INSERT INTO uses_players (use_id, player_id, user_id) VALUES
                (1, 100, 1), (1, 101, 1), (2, 101, 1), (3, 200, 2);
            INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-08-01')");
        require_once PRIVATE_PATH . '/analysis.php';
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

    private function schemaTable(string $table): string
    {
        $schema = file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=[^;]*;/s', $schema, $match);
        return $match[0];
    }

    private function report(): array
    {
        return analysis_report_for_user($this->db, 1, '2026-09-21');
    }

    public function test_a_legacy_play_later_than_the_latest_use_is_the_items_last_use(): void
    {
        $lastUse = array_column((new Items($this->db, 1))->list(), 'last_use', 'id')[10];
        $this->assertSame('2026-08-01', $lastUse);

        $neglected = array_column($this->report()['neglected'], null, 'id');
        $this->assertSame($lastUse, $neglected[10]['last_used']);
        $this->assertSame(51, $neglected[10]['days_idle']);
        $this->assertSame(
            ['Last 30 days' => 1, '31-90 days' => 1, '91-365 days' => 0, 'Over a year' => 0, 'Never used' => 0],
            array_column($this->report()['recency']['buckets'], 'count', 'label')
        );
    }

    public function test_another_owners_items_uses_and_people_never_appear(): void
    {
        $report = $this->report();

        $this->assertSame(2, $report['totals']['items']);
        $this->assertSame(2, $report['totals']['uses']);
        $this->assertSame([11, 10], array_column($report['top_all_time'], 'id'));
        $this->assertSame(['Home'], array_column($report['settings'], 'setting'));
        $this->assertNotContains(200, array_column($report['company']['people'], 'id'));
        // Azul has no Type, so its recent use shows under "-".
        $this->assertSame([['label' => '-', 'count' => 1]], $report['types']);
    }

    public function test_the_person_marked_as_the_owner_is_not_company(): void
    {
        $company = $this->report()['company'];

        $this->assertSame([['id' => 100, 'name' => 'Sam Lee', 'count' => 1, 'last_shared' => '2026-03-01']], $company['people']);
        $this->assertSame(1, $company['shared_uses']);
        $this->assertSame(1, $company['solo_uses']);
        $this->assertSame(1, $this->report()['totals']['people']);
    }
}
