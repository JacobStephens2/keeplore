<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: the Quick record popup partial, rendered for owner 1 from $db and
 * $user_id. The fixture gives owner 1 one use with no Setting.
 */
final class QuickRecordPopupTest extends TestCase
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
        require_once PRIVATE_PATH . '/classes/Uses.php';
        require_once PRIVATE_PATH . '/classes/Preferences.php';
        if (!defined('API_ORIGIN')) {
            define('API_ORIGIN', 'api.example.test');
        }
        $_SESSION = ['user_id' => 1, 'player_id' => 3, 'FullName' => 'Jo Owner', 'csrf_token' => 'tok'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_the_popup_posts_the_fields_record_use_reads(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('name="artifact[id]"', $html);
        $this->assertStringContainsString('name="artifact[name]"', $html);
        $this->assertStringContainsString('name="user[0][id]" id="record-modal-owner-id" value="3"', $html);
        $this->assertStringContainsString('name="user[0][name]" value="Jo Owner"', $html);
        $this->assertStringContainsString('name="useDate"', $html);
        $this->assertStringContainsString('name="Note"', $html);
        $this->assertStringContainsString('name="NotesTwo"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('action="/uses/record-new.php"', $html);
        $this->assertStringContainsString('id="record-modal-user-search"', $html);
        $this->assertStringContainsString('+ New person', $html);
        $this->assertStringContainsString('Open full form', $html);
        $this->assertStringContainsString('data-people-search-url="https://api.example.test/users.php"', $html);
        $this->assertStringContainsString('data-user-id="1"', $html);
    }

    public function test_the_setting_is_the_owners_last_setting(): void
    {
        $this->db->query("UPDATE users SET default_setting = 'Library' WHERE id = 1");
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date, note) VALUES
            (10, 1, '2026-03-01', 'Home'), (10, 1, '2026-02-15', 'Cafe'), (10, 2, '2026-04-01', 'Office')");

        $this->assertSame('Cafe', $this->setting($this->render()));
    }

    public function test_the_setting_falls_back_to_the_default_setting(): void
    {
        $this->db->query("UPDATE users SET default_setting = 'Library' WHERE id = 1");

        $this->assertSame('Library', $this->setting($this->render()));
    }

    public function test_the_setting_is_empty_with_neither(): void
    {
        $this->assertSame('', $this->setting($this->render()));
    }

    private function setting(string $html): string
    {
        $this->assertSame(1, preg_match('/name="Note" id="record-modal-setting" value="([^"]*)"/', $html, $match), $html);
        return html_entity_decode($match[1]);
    }

    private function render(): string
    {
        $db = $this->db;
        $user_id = 1;
        ob_start();
        require SHARED_PATH . '/quick_record_popup.php';
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
