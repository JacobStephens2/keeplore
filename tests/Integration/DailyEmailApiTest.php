<?php

namespace Tests\Integration;

use ApiCaller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RecordingMailer.php';

/**
 * Seam: send_daily_email_over_api(), the HTTP Daily email trigger's GET. It
 * sends the signed-in owner their Daily email now, through the Mailer it is
 * given, and only when the query's userID is that owner. The caller passes
 * Sec-Fetch-Site: cross-site is refused before that email is sent, and
 * same-site still sends. Agent keys are refused (ADR-0002). The result is
 * the status code and the response fields.
 */
final class DailyEmailApiTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private RecordingMailer $mailer;

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
        require_once PRIVATE_PATH . '/daily_email_api.php';
        defined('DOMAIN') || define('DOMAIN', 'keeplore.app');
        defined('DEV_EMAIL') || define('DEV_EMAIL', 'dev@keeplore.app');

        // Catan, last used 2026-02-01 every 90 days, is overdue from 2026-07-31.
        $this->db->query("UPDATE users SET email = 'owner@keeplore.app' WHERE id = 1");
        $this->db->query("UPDATE users SET email = 'other@keeplore.app' WHERE id = 2");
        $this->mailer = new RecordingMailer();
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
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
        return ApiCaller::session($this->db, $userId);
    }

    private function agentKey(int $userId = 1): ApiCaller
    {
        return ApiCaller::agentKey($this->db, $userId);
    }

    private function masterKey(): ApiCaller
    {
        return ApiCaller::masterKey($this->db);
    }

    public function test_the_signed_in_owner_is_sent_their_email(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer, null);

        $this->assertSame(200, $status);
        $this->assertSame(['userID' => 1, 'count_to_notify_about' => 1], $fields);
        $this->assertCount(1, $this->mailer->sent);
        [$to, $subject, $html] = $this->mailer->sent[0];
        $this->assertSame('owner@keeplore.app', $to);
        $this->assertSame('Interactions Due', $subject);
        $this->assertStringContainsString('Catan', $html);
    }

    public function test_a_cross_site_request_is_refused_before_the_daily_email_is_sent(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer, 'cross-site');

        $this->assertSame(403, $status);
        $this->assertSame('A cross-site request cannot send the Daily email.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_a_same_site_request_still_sends_the_daily_email(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer, 'same-site');

        $this->assertSame(200, $status);
        $this->assertSame(['userID' => 1, 'count_to_notify_about' => 1], $fields);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_an_agent_key_is_refused(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->agentKey(), ['userID' => '1'], $this->mailer, null);

        $this->assertSame(403, $status);
        $this->assertSame('Agent keys permit reads plus the kept toggle only.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public static function notTheSignedInUser(): array
    {
        return [
            'another user' => [['userID' => '2']],
            'no userID' => [[]],
            'a word' => [['userID' => 'me']],
        ];
    }

    /** @dataProvider notTheSignedInUser */
    public function test_only_the_signed_in_user_may_be_sent_the_email(array $query): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), $query, $this->mailer, null);

        $this->assertSame(403, $status);
        $this->assertSame('You may only send this email to yourself.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_the_master_key_has_no_signed_in_user(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->masterKey(), ['userID' => '1'], $this->mailer, null);

        $this->assertSame(403, $status);
        $this->assertSame('You may only send this email to yourself.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_repeated_requests_for_the_same_account_return_429_regardless_of_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        [$firstStatus, $firstFields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer, null);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        [$secondStatus, $secondFields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer, null);

        $this->assertSame(200, $firstStatus);
        $this->assertSame(['userID' => 1, 'count_to_notify_about' => 1], $firstFields);
        $this->assertSame(429, $secondStatus);
        $this->assertIsString($secondFields['message'] ?? null);
        $this->assertNotSame('', $secondFields['message']);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_another_account_can_send_during_cooldown_and_cooled_down_account_sends_after_expiry(): void
    {
        $this->assertSame(200, send_daily_email_over_api($this->db, $this->session(1), ['userID' => '1'], $this->mailer, null, '2026-08-01 12:00:00')[0]);
        [$otherStatus, $otherFields] = send_daily_email_over_api($this->db, $this->session(2), ['userID' => '2'], $this->mailer, null, '2026-08-01 12:05:00');
        $this->assertSame(200, $otherStatus);
        $this->assertSame(['userID' => 2, 'count_to_notify_about' => 1], $otherFields);
        $this->assertSame(429, send_daily_email_over_api($this->db, $this->session(1), ['userID' => '1'], $this->mailer, null, '2026-08-01 12:14:59')[0]);

        $this->assertSame(200, send_daily_email_over_api($this->db, $this->session(1), ['userID' => '1'], $this->mailer, null, '2026-08-01 12:15:00')[0]);
        $this->assertSame(429, send_daily_email_over_api($this->db, $this->session(2), ['userID' => '2'], $this->mailer, null, '2026-08-01 12:15:00')[0]);
        $this->assertCount(3, $this->mailer->sent);
        $this->assertSame('owner@keeplore.app', $this->mailer->sent[0][0]);
        $this->assertSame('other@keeplore.app', $this->mailer->sent[1][0]);
        $this->assertSame('owner@keeplore.app', $this->mailer->sent[2][0]);
    }

    public function test_refused_requests_do_not_consume_the_accounts_cooldown(): void
    {
        $this->assertSame(403, send_daily_email_over_api($this->db, $this->session(1), ['userID' => '1'], $this->mailer, 'cross-site')[0]);
        $this->assertSame(403, send_daily_email_over_api($this->db, $this->agentKey(1), ['userID' => '1'], $this->mailer, null)[0]);
        $this->assertSame(403, send_daily_email_over_api($this->db, $this->masterKey(), ['userID' => '1'], $this->mailer, null)[0]);
        $this->assertSame(403, send_daily_email_over_api($this->db, $this->session(1), ['userID' => '2'], $this->mailer, null)[0]);
        $this->assertSame(403, send_daily_email_over_api($this->db, $this->session(2), ['userID' => '1'], $this->mailer, null)[0]);
        $this->assertSame([], $this->mailer->sent);

        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(1), ['userID' => '1'], $this->mailer, null);
        $this->assertSame(200, $status);
        $this->assertSame(['userID' => 1, 'count_to_notify_about' => 1], $fields);
        $this->assertCount(1, $this->mailer->sent);

        $this->assertSame(200, send_daily_email_over_api($this->db, $this->session(2), ['userID' => '2'], $this->mailer, null)[0]);
        $this->assertCount(2, $this->mailer->sent);
    }
}
