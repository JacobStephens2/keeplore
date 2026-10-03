<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: the type lists pages show, read through Types for the session
 * owner. Owner 1 has board-game (1) and film (2); owner 2 has table game (3).
 */
final class TypeListsTest extends TestCase
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
        $this->db->query('ALTER TABLE types MODIFY id INT AUTO_INCREMENT, ADD COLUMN user_id INT NULL');
        $this->db->query('UPDATE types SET user_id = 1');
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (3, 'table game', 2)");
        require_once PRIVATE_PATH . '/classes/Types.php';
        require_once PRIVATE_PATH . '/items_list.php';
        $GLOBALS['db'] = $this->db;
        $_SESSION = ['user_id' => 2];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['db']);
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_the_items_filter_defaults_to_only_the_owners_types(): void
    {
        [, $types] = items_list_load_filter_defaults(2);

        $this->assertSame(['table game' => 3], $types);
    }

    public function test_the_type_checkboxes_offer_only_the_session_owners_types(): void
    {
        $html = $this->render('artifact_type_checkboxes.php', ['type' => []]);

        $this->assertStringContainsString('value="3"', $html);
        $this->assertStringContainsString('data-type-ids="[&quot;3&quot;]"', $html);
        $this->assertStringNotContainsString('board-game', $html);
        $this->assertStringNotContainsString('film', $html);
    }

    public function test_the_type_options_offer_only_the_session_owners_types(): void
    {
        $html = $this->render('artifact_type_options.php', ['type_id' => '']);

        $this->assertStringContainsString('value="3"', $html);
        $this->assertStringNotContainsString('board-game', $html);
    }

    public function test_the_type_search_offers_only_the_session_owners_types(): void
    {
        $html = $this->render('artifact_type_search.php', ['type_id' => 3]);

        $this->assertStringContainsString('data-id="3"', $html);
        $this->assertStringContainsString('value="table game"', $html);
        $this->assertStringNotContainsString('board-game', $html);
    }

    public function test_the_type_search_preselects_nothing_for_another_owners_type(): void
    {
        $html = $this->render('artifact_type_search.php', ['type_id' => 1]);

        $this->assertStringContainsString('name="type" id="type" value=""', $html);
    }

    private function render(string $partial, array $vars): string
    {
        if (!defined('DEFAULT_TYPE')) {
            define('DEFAULT_TYPE', 0);
        }
        $db = $this->db;
        extract($vars);
        ob_start();
        require SHARED_PATH . '/' . $partial;
        return (string) ob_get_clean();
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
