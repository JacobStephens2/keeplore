<?php

namespace Tests\Integration;

use AccountInvalid;
use Accounts;
use OutOfBoundsException;
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
        $this->runSql('ALTER TABLE players ADD COLUMN represents_user_id INT DEFAULT NULL');
        require_once PRIVATE_PATH . '/classes/Accounts.php';
        defined('DOMAIN') || define('DOMAIN', 'keeplore.app');
        defined('APP_NAME') || define('APP_NAME', 'Keeplore');
        defined('DEV_EMAIL') || define('DEV_EMAIL', 'dev@keeplore.app');

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

    /** Whether the account's password is $password. */
    private function passwordIs(int $id, string $password): bool
    {
        return ($this->accounts()->logIn($this->accounts()->find($id)['username'], $password)['id'] ?? null) === $id;
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
        $this->fail('The input must be refused as invalid.');
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
    private const ACCOUNT_KEYS = ['id', 'first_name', 'last_name', 'name', 'email', 'username', 'user_group', 'person_id'];

    private static function registration(array $changes = []): array
    {
        return $changes + [
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada@keeplore.app',
            'username' => 'adabyron1815',
            'password' => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ];
    }

    private function accountCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
    }

    public function test_find_returns_the_account_without_the_password_hash(): void
    {
        $account = $this->accounts()->find(1);

        $this->assertSame([
            'id' => 1,
            'first_name' => 'Sam',
            'last_name' => 'Lee',
            'name' => 'Sam Lee',
            'email' => 'owner@keeplore.app',
            'username' => 'ownerusername',
            'user_group' => 1,
            'person_id' => null,
        ], $account);
        $this->assertNull($this->accounts()->find(99));
    }

    public function test_an_accounts_person_is_the_person_marked_as_them(): void
    {
        $this->db->query('UPDATE users SET player_id = 101 WHERE id = 1');
        $this->db->query('UPDATE players SET represents_user_id = 2 WHERE id = 200');

        $this->assertSame(101, $this->accounts()->find(1)['person_id']);
        $this->assertSame(200, $this->accounts()->find(2)['person_id']);
        $this->assertSame(200, $this->accounts()->logIn('otherusername', self::OLD_PASSWORD)['person_id']);
    }

    public function test_log_in_by_username_or_email_returns_the_account(): void
    {
        $account = $this->accounts()->find(2);

        $this->assertSame($account, $this->accounts()->logIn('otherusername', self::OLD_PASSWORD));
        $this->assertSame($account, $this->accounts()->logIn('other+tag@keeplore.app', self::OLD_PASSWORD));
        $this->assertSame(1, $this->accounts()->logIn('owner@keeplore.app', self::OLD_PASSWORD)['id']);
    }

    public function test_an_unknown_name_and_a_wrong_password_both_fail_to_log_in(): void
    {
        $this->assertNull($this->accounts()->logIn('nobodyusername', self::OLD_PASSWORD));
        $this->assertNull($this->accounts()->logIn('ownerusername', self::NEW_PASSWORD));
        $this->assertNull($this->accounts()->logIn('ownerusername', ''));
        $this->assertNull($this->accounts()->logIn('', ''));
    }

    public function test_register_creates_an_account_that_can_log_in(): void
    {
        $account = $this->accounts()->register(self::registration([
            'first_name' => ' Ada ',
            'email' => ' ada@keeplore.app ',
        ]));

        $this->assertSame(self::ACCOUNT_KEYS, array_keys($account));
        $this->assertSame([
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'name' => 'Ada Byron',
            'email' => 'ada@keeplore.app',
            'username' => 'adabyron1815',
            'user_group' => 1,
            'person_id' => null,
        ], array_slice($account, 1));
        $this->assertSame($account, $this->accounts()->find($account['id']));
        $this->assertSame($account, $this->accounts()->logIn('adabyron1815', self::NEW_PASSWORD));
        $this->assertSame($account, $this->accounts()->logIn('ada@keeplore.app', self::NEW_PASSWORD));
    }

    public function test_register_sends_the_developer_an_escaped_new_account_notice(): void
    {
        $this->accounts()->register(self::registration(['last_name' => '<b>Byron</b>', 'device' => 'Phone & "tablet"']));

        $this->assertCount(1, $this->mailer->sent);
        [$to, $subject, $html] = $this->mailer->sent[0];
        $this->assertSame('dev@keeplore.app', $to);
        $this->assertSame('Keeplore — New Account Created', $subject);
        $this->assertStringContainsString('Ada &lt;b&gt;Byron&lt;/b&gt;', $html);
        $this->assertStringContainsString('adabyron1815', $html);
        $this->assertStringContainsString('ada@keeplore.app', $html);
        $this->assertStringContainsString('2026-06-01T12:00:00', $html);
        $this->assertStringContainsString('Phone &amp; &quot;tablet&quot;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_register_succeeds_and_logs_when_the_notice_fails(): void
    {
        $this->mailer->failFor('dev@keeplore.app', 'SMTP down');
        $log = tempnam(sys_get_temp_dir(), 'keeplore-log');
        $previousLog = ini_set('error_log', $log);
        try {
            $account = $this->accounts()->register(self::registration());
        } finally {
            ini_set('error_log', $previousLog);
        }

        $this->assertSame($account, $this->accounts()->logIn('adabyron1815', self::NEW_PASSWORD));
        $this->assertStringContainsString('SMTP down', file_get_contents($log));
        unlink($log);
    }

    public static function brokenProfiles(): array
    {
        return [
            'blank first name' => [['first_name' => ' '], ['First name cannot be blank.']],
            'short first name' => [['first_name' => 'A'], ['First name must be between 2 and 255 characters.']],
            'long first name' => [['first_name' => str_repeat('a', 256)], ['First name must be between 2 and 255 characters.']],
            'blank last name' => [['last_name' => ''], ['Last name cannot be blank.']],
            'short last name' => [['last_name' => 'B'], ['Last name must be between 2 and 255 characters.']],
            'long last name' => [['last_name' => str_repeat('b', 256)], ['Last name must be between 2 and 255 characters.']],
            'blank email' => [['email' => ''], ['Email cannot be blank.']],
            'long email' => [['email' => str_repeat('a', 244) . '@keeplore.app'], ['Email must be at most 255 characters.']],
            'malformed email' => [['email' => 'not-an-email'], ['Email must be a valid format.']],
            'blank username' => [['username' => ''], ['Username cannot be blank.']],
            'short username' => [['username' => 'short'], ['Username must be between 8 and 255 characters.']],
            'long username' => [['username' => str_repeat('u', 256)], ['Username must be between 8 and 255 characters.']],
            'taken username' => [['username' => 'ownerusername'], ['That username is taken. Try another.']],
            'taken email' => [['email' => 'owner@keeplore.app'], ['That email already belongs to an account. Log in or reset your password.']],
        ];
    }

    /** @dataProvider brokenProfiles */
    public function test_register_refuses_a_profile_that_breaks_the_rules(array $changes, array $errors): void
    {
        $invalid = $this->invalid(fn () => $this->accounts()->register(self::registration($changes)));

        $this->assertSame($errors, $invalid->errors);
        $this->assertSame(2, $this->accountCount());
        $this->assertSame([], $this->mailer->sent);
    }

    /** @dataProvider brokenPasswords */
    public function test_register_refuses_a_password_that_breaks_the_rules(string $password, string $confirm, array $errors): void
    {
        $registration = self::registration(['password' => $password, 'confirm_password' => $confirm]);

        $invalid = $this->invalid(fn () => $this->accounts()->register($registration));

        $this->assertSame($errors, $invalid->errors);
        $this->assertSame(2, $this->accountCount());
    }

    public function test_register_lists_every_problem_at_once(): void
    {
        $invalid = $this->invalid(fn () => $this->accounts()->register([
            'first_name' => 'A',
            'last_name' => '',
            'email' => 'other+tag@keeplore.app',
            'username' => 'otherusername',
            'password' => 'short',
            'confirm_password' => 'shorter',
        ]));

        $this->assertSame([
            'First name must be between 2 and 255 characters.',
            'Last name cannot be blank.',
            'That email already belongs to an account. Log in or reset your password.',
            'That username is taken. Try another.',
            'Password must contain 12 or more characters.',
            'Password must contain at least 1 uppercase letter.',
            'Password must contain at least 1 number.',
            'Password must contain at least 1 symbol.',
            'Password and confirm password must match.',
        ], $invalid->errors);
        $this->assertSame(2, $this->accountCount());
    }

    private static function profileChanges(array $changes = []): array
    {
        return $changes + [
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada@keeplore.app',
            'username' => 'adabyron1815',
        ];
    }

    public function test_update_profile_replaces_the_name_email_and_username(): void
    {
        $account = $this->accounts()->updateProfile(1, self::profileChanges(['first_name' => ' Ada ', 'email' => ' ada@keeplore.app ']));

        $this->assertSame([
            'id' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'name' => 'Ada Byron',
            'email' => 'ada@keeplore.app',
            'username' => 'adabyron1815',
            'user_group' => 1,
            'person_id' => null,
        ], $account);
        $this->assertSame($account, $this->accounts()->find(1));
        $this->assertSame(1, $this->accounts()->logIn('adabyron1815', self::OLD_PASSWORD)['id']);
        $this->assertSame('otherusername', $this->accounts()->find(2)['username']);
    }

    public function test_update_profile_keeps_the_accounts_own_username_and_email(): void
    {
        $account = $this->accounts()->updateProfile(1, self::profileChanges([
            'email' => 'owner@keeplore.app',
            'username' => 'ownerusername',
        ]));

        $this->assertSame('Ada Byron', $account['name']);
        $this->assertSame('owner@keeplore.app', $account['email']);
        $this->assertSame('ownerusername', $account['username']);
        $this->assertSame($account, $this->accounts()->find(1));
    }

    public static function brokenProfileUpdates(): array
    {
        $taken = [
            'taken username' => [['username' => 'otherusername'], ['That username is taken. Try another.']],
            'taken email' => [['email' => 'other+tag@keeplore.app'], ['That email already belongs to another account.']],
        ];
        return array_diff_key(self::brokenProfiles(), $taken) + $taken;
    }

    /** @dataProvider brokenProfileUpdates */
    public function test_update_profile_refuses_a_profile_that_breaks_the_rules(array $changes, array $errors): void
    {
        $before = $this->accounts()->find(1);

        $invalid = $this->invalid(fn () => $this->accounts()->updateProfile(1, self::profileChanges($changes)));

        $this->assertSame($errors, $invalid->errors);
        $this->assertSame($before, $this->accounts()->find(1));
    }

    public function test_update_profile_lists_every_problem_at_once(): void
    {
        $invalid = $this->invalid(fn () => $this->accounts()->updateProfile(1, [
            'first_name' => '',
            'last_name' => 'B',
            'email' => 'other+tag@keeplore.app',
            'username' => 'otherusername',
        ]));

        $this->assertSame([
            'First name cannot be blank.',
            'Last name must be between 2 and 255 characters.',
            'That email already belongs to another account.',
            'That username is taken. Try another.',
        ], $invalid->errors);
        $this->assertSame('Sam Lee', $this->accounts()->find(1)['name']);
    }

    public function test_update_profile_of_no_account_throws_out_of_bounds(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->accounts()->updateProfile(99, self::profileChanges());
    }
}
