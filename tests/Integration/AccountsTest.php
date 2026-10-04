<?php

namespace Tests\Integration;

use AccountInvalid;
use Accounts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/RecordingMailer.php';

final class AccountsTest extends TestCase
{
    private const OLD_PASSWORD = 'Old-password-1';
    private const NEW_PASSWORD = 'New-password-12';
    private const NOW = '2026-06-01 12:00:00';

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
        require_once PRIVATE_PATH . '/classes/Accounts.php';
        defined('DOMAIN') || define('DOMAIN', 'keeplore.app');
        defined('APP_NAME') || define('APP_NAME', 'Keeplore');

        $this->giveAccount(1, 'owner@keeplore.app', 'ownerusername');
        $this->giveAccount(2, 'other+tag@keeplore.app', 'otherusername');
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

    private function giveAccount(int $id, string $email, string $username): void
    {
        $hash = password_hash(self::OLD_PASSWORD, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare(
            "UPDATE users SET first_name = 'Sam', last_name = 'Lee', email = ?, username = ?, hashed_password = ? WHERE id = ?"
        );
        $stmt->bind_param('sssi', $email, $username, $hash, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** Accounts as of $now, NOW unless given. */
    private function accounts(string $now = self::NOW): Accounts
    {
        return new Accounts($this->db, $this->mailer, $now);
    }

    /** Whether the account's password is $password (Accounts can't log in yet). */
    private function passwordIs(int $id, string $password): bool
    {
        $hash = $this->db->query("SELECT hashed_password FROM users WHERE id = $id")->fetch_row()[0];
        return password_verify($password, $hash);
    }

    /** Request a reset for $email and return [email, key] from the link in the email sent. */
    private function requestLink(string $email, string $now = self::NOW): array
    {
        $this->accounts($now)->requestPasswordReset($email);
        [$to, , $html] = $this->mailer->sent[array_key_last($this->mailer->sent)];
        $this->assertSame($email, $to);
        return $this->followLink($html);
    }

    /** The email and key the reset page reads from the link in $html. */
    private function followLink(string $html): array
    {
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $html, $match));
        $url = html_entity_decode($match[1]);
        $this->assertStringStartsWith('https://keeplore.app/reset-password/reset-password.php?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('reset', $query['action']);
        return [$query['email'], $query['key']];
    }

    private function invalid(callable $write): AccountInvalid
    {
        try {
            $write();
        } catch (AccountInvalid $invalid) {
            return $invalid;
        }
        $this->fail('The reset must be refused as invalid.');
    }

    public function test_a_requested_link_resets_the_password(): void
    {
        [$email, $key] = $this->requestLink('owner@keeplore.app');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('Password Reset', $this->mailer->sent[0][1]);
        $this->assertSame('owner@keeplore.app', $email);
        $this->assertTrue($this->accounts()->resetLinkIsValid($email, $key));

        $this->accounts()->resetPassword($email, $key, self::NEW_PASSWORD, self::NEW_PASSWORD);

        $this->assertTrue($this->passwordIs(1, self::NEW_PASSWORD));
        $this->assertTrue($this->passwordIs(2, self::OLD_PASSWORD));
    }

    public function test_a_reset_without_a_key_or_with_a_made_up_one_is_refused(): void
    {
        foreach (['', str_repeat('a', 64)] as $key) {
            $this->assertFalse($this->accounts()->resetLinkIsValid('owner@keeplore.app', $key));
            $invalid = $this->invalid(fn () => $this->accounts()->resetPassword('owner@keeplore.app', $key, self::NEW_PASSWORD, self::NEW_PASSWORD));
            $this->assertSame(['This reset link is invalid or has expired.'], $invalid->errors);
        }

        $this->assertTrue($this->passwordIs(1, self::OLD_PASSWORD));
    }

    public function test_a_wrong_key_is_refused_even_with_a_reset_requested(): void
    {
        [$email, $key] = $this->requestLink('owner@keeplore.app');
        [, $otherKey] = $this->requestLink('other+tag@keeplore.app');
        $wrongKey = substr($key, 0, -1) . ($key[-1] === 'a' ? 'b' : 'a');

        foreach ([$wrongKey, $otherKey] as $badKey) {
            $this->assertFalse($this->accounts()->resetLinkIsValid($email, $badKey));
            $this->invalid(fn () => $this->accounts()->resetPassword($email, $badKey, self::NEW_PASSWORD, self::NEW_PASSWORD));
        }

        $this->assertTrue($this->passwordIs(1, self::OLD_PASSWORD));
        $this->assertTrue($this->accounts()->resetLinkIsValid($email, $key));
    }

    public function test_a_link_expires_one_day_after_it_was_requested(): void
    {
        [$email, $key] = $this->requestLink('owner@keeplore.app');

        $this->assertTrue($this->accounts('2026-06-02 12:00:00')->resetLinkIsValid($email, $key));
        $this->assertFalse($this->accounts('2026-06-02 12:00:01')->resetLinkIsValid($email, $key));
        $this->invalid(fn () => $this->accounts('2026-06-02 12:00:01')->resetPassword($email, $key, self::NEW_PASSWORD, self::NEW_PASSWORD));

        $this->assertTrue($this->passwordIs(1, self::OLD_PASSWORD));
    }

    public function test_a_reset_uses_up_every_key_for_the_email(): void
    {
        [$email, $firstKey] = $this->requestLink('owner@keeplore.app');
        [, $secondKey] = $this->requestLink('owner@keeplore.app');

        $this->accounts()->resetPassword($email, $secondKey, self::NEW_PASSWORD, self::NEW_PASSWORD);

        foreach ([$firstKey, $secondKey] as $key) {
            $this->assertFalse($this->accounts()->resetLinkIsValid($email, $key));
            $this->invalid(fn () => $this->accounts()->resetPassword($email, $key, 'Another-password-3', 'Another-password-3'));
        }
        $this->assertTrue($this->passwordIs(1, self::NEW_PASSWORD));
    }

    public static function brokenPasswords(): array
    {
        return [
            'blank' => ['', '', ['Password cannot be blank.', 'Confirm password cannot be blank.']],
            'too short' => ['Short-pw-1', 'Short-pw-1', ['Password must contain 12 or more characters.']],
            'no uppercase' => ['new-password-12', 'new-password-12', ['Password must contain at least 1 uppercase letter.']],
            'no lowercase' => ['NEW-PASSWORD-12', 'NEW-PASSWORD-12', ['Password must contain at least 1 lowercase letter.']],
            'no number' => ['New-password-xy', 'New-password-xy', ['Password must contain at least 1 number.']],
            'no symbol' => ['Newpassword123', 'Newpassword123', ['Password must contain at least 1 symbol.']],
            'confirmation blank' => [self::NEW_PASSWORD, '', ['Confirm password cannot be blank.']],
            'confirmation differs' => [self::NEW_PASSWORD, 'New-password-13', ['Password and confirm password must match.']],
            'several rules' => ['newpassword', 'newpassword', [
                'Password must contain 12 or more characters.',
                'Password must contain at least 1 uppercase letter.',
                'Password must contain at least 1 number.',
                'Password must contain at least 1 symbol.',
            ]],
        ];
    }

    /** @dataProvider brokenPasswords */
    public function test_a_password_that_breaks_the_rules_is_refused_and_the_link_still_works(string $password, string $confirm, array $errors): void
    {
        [$email, $key] = $this->requestLink('owner@keeplore.app');

        $invalid = $this->invalid(fn () => $this->accounts()->resetPassword($email, $key, $password, $confirm));

        $this->assertSame($errors, $invalid->errors);
        $this->assertTrue($this->passwordIs(1, self::OLD_PASSWORD));
        $this->assertTrue($this->accounts()->resetLinkIsValid($email, $key));
    }

    public function test_a_bad_key_and_a_bad_password_are_both_listed(): void
    {
        $invalid = $this->invalid(fn () => $this->accounts()->resetPassword('owner@keeplore.app', 'made-up', 'short', 'short'));

        $this->assertContains('This reset link is invalid or has expired.', $invalid->errors);
        $this->assertContains('Password must contain 12 or more characters.', $invalid->errors);
    }

    public function test_the_link_url_encodes_an_email_with_a_plus(): void
    {
        $this->accounts()->requestPasswordReset('other+tag@keeplore.app');
        [, , $html] = $this->mailer->sent[0];
        $this->assertStringContainsString('email=other%2Btag%40keeplore.app', $html);

        [$email, $key] = $this->followLink($html);
        $this->assertSame('other+tag@keeplore.app', $email);
        $this->accounts()->resetPassword($email, $key, self::NEW_PASSWORD, self::NEW_PASSWORD);

        $this->assertTrue($this->passwordIs(2, self::NEW_PASSWORD));
        $this->assertTrue($this->passwordIs(1, self::OLD_PASSWORD));
    }

    public function test_an_email_with_no_account_is_sent_nothing(): void
    {
        $this->accounts()->requestPasswordReset('nobody@keeplore.app');

        $this->assertSame([], $this->mailer->sent);
        $this->assertFalse($this->accounts()->resetLinkIsValid('nobody@keeplore.app', ''));
    }

    public function test_a_mailer_failure_throws(): void
    {
        $this->mailer->failFor('owner@keeplore.app', 'SMTP down');

        $this->expectException(RuntimeException::class);
        $this->accounts()->requestPasswordReset('owner@keeplore.app');
    }
}
