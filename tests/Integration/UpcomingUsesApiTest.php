<?php

namespace Tests\Integration;

use ApiCaller;
use PHPUnit\Framework\TestCase;

/**
 * Seam: list_upcoming_uses_over_api(), the HTTP upcoming uses GET that
 * native notifications read. It lists the owner's Use-by queue entries due
 * within 60 days with their notification Preferences; the master key has no
 * queue of its own. The result is the status code and the response fields.
 */
final class UpcomingUsesApiTest extends TestCase
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
        require_once PRIVATE_PATH . '/api_request.php';
        require_once PRIVATE_PATH . '/upcoming_uses_api.php';
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

    private function session(int $userId = 1): ApiCaller
    {
        return ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'session', 'user_id' => $userId]);
    }

    private function masterKey(): ApiCaller
    {
        return ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'api_key']);
    }

    /** A kept item of the owner's with its own frequency, last used $daysAgo days before today. */
    private function addItemUsed(int $id, string $title, int $frequency, int $daysAgo, int $userId = 1): void
    {
        $this->db->query("INSERT INTO games (id, user_id, Title, type_id, interaction_frequency_days) VALUES ($id, $userId, '$title', 1, $frequency)");
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date) VALUES ($id, $userId, DATE_SUB('" . record_use_today() . "', INTERVAL $daysAgo DAY))");
    }

    public function test_a_session_reads_its_queue_within_sixty_days_with_its_notification_preferences(): void
    {
        // Catan was last used 2026-02-01 every 90 days, so it is past due
        // from 2026-07-31 on. Azul is to get rid of and Arrival isn't kept.
        $this->addItemUsed(30, 'Due soon', 10, 15);
        $this->addItemUsed(31, 'Upcoming', 30, 30);
        $this->addItemUsed(32, 'Far off', 100, 1);
        $this->addItemUsed(40, 'Someone else', 10, 15, 2);
        $this->db->query('UPDATE users SET native_notify_hour = 7, native_notify_lead_days = 2, native_notify_past_due = 0 WHERE id = 1');

        [$status, $fields] = list_upcoming_uses_over_api($this->db, $this->session());

        $this->assertSame(200, $status);
        $this->assertSame(true, $fields['authenticated']);
        $this->assertSame(record_use_today(), $fields['today']);
        $this->assertSame('America/New_York', $fields['timezone']);
        $this->assertSame(60, $fields['horizon_days']);
        $this->assertEquals(90, $fields['default_interval_days']);
        $this->assertEquals(['enabled' => true, 'hour' => 7, 'lead_days' => 2, 'past_due' => false], $fields['notification_prefs']);

        $byTitle = array_column($fields['items'], null, 'title');
        $this->assertSame(['Catan', 'Due soon', 'Upcoming'], array_keys($byTitle));
        $this->assertSame('past_due', $byTitle['Catan']['status']);
        $this->assertSame('2026-02-01', $byTitle['Catan']['most_recent_interaction']);
        $this->assertSame('2026-07-31', $byTitle['Catan']['use_by_date']);
        $this->assertSame(['id', 'title', 'use_by_date', 'most_recent_interaction', 'interval_days', 'status'], array_keys($byTitle['Due soon']));
        $this->assertSame(30, $byTitle['Due soon']['id']);
        $this->assertSame(10.0, $byTitle['Due soon']['interval_days']);
        $this->assertSame('due_soon', $byTitle['Due soon']['status']);
        $this->assertSame('upcoming', $byTitle['Upcoming']['status']);
    }

    public function test_the_master_key_is_refused(): void
    {
        [$status, $fields] = list_upcoming_uses_over_api($this->db, $this->masterKey());

        $this->assertSame(400, $status);
        $this->assertSame('This endpoint requires user-scoped authentication (JWT cookie).', $fields['message']);
    }

    public function test_a_wrong_method_from_the_master_key_gets_405_before_the_owner_check(): void
    {
        [$status, $body] = answer_api_request($this->db, 'upcoming-interactions', [
            'GET' => fn (ApiCaller $caller) => list_upcoming_uses_over_api($this->db, $caller),
        ], [
            'method' => 'POST',
            'query' => [],
            'body' => null,
            'authentication' => (object) ['authenticated' => true, 'auth_type' => 'api_key'],
        ]);

        $this->assertSame(405, $status);
        $this->assertSame('Method not allowed. Supported methods: GET', $body->message);
    }
}
