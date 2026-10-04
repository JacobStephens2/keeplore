<?php

namespace Tests\Integration;

use ApiCaller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RecordingMailer.php';

/**
 * Seam: send_daily_email_over_api(), the HTTP Daily email trigger's GET. It
 * sends the signed-in owner their Daily email now, through the Mailer it is
 * given, and only when the query's userID is that owner. Agent keys are
 * refused (ADR-0002). The result is the status code and the response fields.
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
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), ['userID' => '1'], $this->mailer);

        $this->assertSame(200, $status);
        $this->assertSame(['userID' => 1, 'count_to_notify_about' => 1], $fields);
        $this->assertCount(1, $this->mailer->sent);
        [$to, $subject, $html] = $this->mailer->sent[0];
        $this->assertSame('owner@keeplore.app', $to);
        $this->assertSame('Interactions Due', $subject);
        $this->assertStringContainsString('Catan', $html);
    }

    public function test_an_agent_key_is_refused(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->agentKey(), ['userID' => '1'], $this->mailer);

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
        [$status, $fields] = send_daily_email_over_api($this->db, $this->session(), $query, $this->mailer);

        $this->assertSame(403, $status);
        $this->assertSame('You may only send this email to yourself.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_the_master_key_has_no_signed_in_user(): void
    {
        [$status, $fields] = send_daily_email_over_api($this->db, $this->masterKey(), ['userID' => '1'], $this->mailer);

        $this->assertSame(403, $status);
        $this->assertSame('You may only send this email to yourself.', $fields['message']);
        $this->assertSame([], $this->mailer->sent);
    }
}
