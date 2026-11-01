<?php

namespace Tests\Integration;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * Seam: is_logged_in(), whether the browser's session or access-token
 * cookie still authenticates the account. A session uses its last-login
 * time and an access token its issued-at time. Either issued before the
 * password last changed does not authenticate, including the browser that
 * completed the reset. A login after the reset still signs the account in.
 * A guest session is not a login.
 */
final class LoginSessionTest extends TestCase
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
        $this->db->query('CREATE TABLE users (
            id INT PRIMARY KEY,
            first_name VARCHAR(255),
            last_name VARCHAR(255),
            email VARCHAR(255),
            username VARCHAR(255),
            user_group INT,
            player_id INT NULL,
            password_changed_at INT UNSIGNED NULL
        )');
        $this->db->query('CREATE TABLE players (
            id INT PRIMARY KEY,
            user_id INT NOT NULL,
            FirstName VARCHAR(255),
            LastName VARCHAR(255),
            represents_user_id INT NULL
        )');
        $this->db->query("INSERT INTO users (id, first_name, last_name, email, username, user_group)
            VALUES (1, 'Sam', 'Lee', 'owner@keeplore.app', 'ownerusername', 1),
                   (2, 'Jo', 'Kim', 'other@keeplore.app', 'otherusername', 1)");
        $GLOBALS['db'] = $this->db;
        $this->defineAuthConstants();
        require_once PRIVATE_PATH . '/classes/Accounts.php';
        require_once PRIVATE_PATH . '/classes/SmtpMailer.php';
        require_once PRIVATE_PATH . '/auth_functions.php';
        $_SESSION = [];
        unset($_COOKIE['access_token']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE['access_token']);
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function defineAuthConstants(): void
    {
        defined('ARTIFACTS_DOMAIN') || define('ARTIFACTS_DOMAIN', '');
        defined('COOKIE_SECURE') || define('COOKIE_SECURE', false);
        defined('SMTP_HOST') || define('SMTP_HOST', 'localhost');
        defined('SMTP_PORT') || define('SMTP_PORT', '587');
        defined('SMTP_USER') || define('SMTP_USER', 'user');
        defined('SMTP_PASS') || define('SMTP_PASS', 'pass');
        defined('SMTP_FROM_EMAIL') || define('SMTP_FROM_EMAIL', 'dev@keeplore.app');
        defined('APP_NAME') || define('APP_NAME', 'Keeplore');
        defined('DEV_EMAIL') || define('DEV_EMAIL', 'dev@keeplore.app');
        defined('DEV_NAME') || define('DEV_NAME', 'Dev');
    }

    /** The account array log_in_user records in the session. */
    private function account(int $id = 1): array
    {
        return [
            'id' => $id,
            'name' => 'Sam Lee',
            'person_id' => null,
            'username' => 'ownerusername',
            'user_group' => 1,
        ];
    }

    private function passwordChangedAt(int $id, int $changedAt): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_changed_at = ? WHERE id = ?');
        $stmt->bind_param('ii', $changedAt, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** An access token whose issued-at time is $issuedAt, as logging in signs it. */
    private function accessToken(int $issuedAt, int $userId = 1): string
    {
        return JWT::encode([
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + 86400,
            'user_id' => $userId,
        ], JWT_SECRET, 'HS256');
    }

    public function test_a_session_from_before_the_password_change_does_not_authenticate(): void
    {
        $changedAt = time() - 60;
        $this->passwordChangedAt(1, $changedAt);
        $_SESSION = [
            'user_id' => 1,
            'player_id' => 5,
            'last_login' => $changedAt - 1,
            'user_group' => 1,
            'logged_in' => true,
        ];

        $this->assertFalse(is_logged_in());

        $_SESSION['last_login'] = $changedAt;
        $this->assertFalse(is_logged_in());
        $_SESSION['last_login'] = $changedAt + 1;
        $this->assertTrue(is_logged_in());
        $_SESSION['user_id'] = 2;
        $_SESSION['last_login'] = $changedAt - 1;
        $this->assertTrue(is_logged_in());
    }

    public function test_the_browser_that_still_holds_the_old_access_token_is_not_signed_back_in(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $changedAt = time() - 60;
        $this->passwordChangedAt(1, $changedAt);
        $_SESSION = [];
        $_COOKIE['access_token'] = $this->accessToken($changedAt - 1);

        $this->assertFalse(is_logged_in());
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        $_COOKIE['access_token'] = $this->accessToken($changedAt);
        $this->assertFalse(is_logged_in());
        $_COOKIE['access_token'] = $this->accessToken($changedAt + 1);
        $this->assertTrue(is_logged_in());
        $this->assertSame(1, $_SESSION['user_id']);
        $this->assertSame($changedAt + 1, $_SESSION['last_login']);
    }

    public function test_a_session_without_a_password_change_still_authenticates(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'player_id' => 5,
            'last_login' => 1,
            'user_group' => 1,
            'logged_in' => true,
        ];

        $this->assertTrue(is_logged_in());

        unset($_SESSION['last_login']);
        $this->assertTrue(is_logged_in());
    }

    public function test_an_account_session_with_no_last_login_does_not_survive_a_password_change(): void
    {
        $this->passwordChangedAt(1, time() - 60);
        $_SESSION = [
            'user_id' => 1,
            'player_id' => 5,
            'user_group' => 1,
            'logged_in' => true,
        ];

        $this->assertFalse(is_logged_in());
    }

    public function test_a_guest_session_is_not_cut_off_by_a_password_change(): void
    {
        $this->passwordChangedAt(1, time() - 60);
        $_SESSION = [
            'user_id' => 1,
            'guest_mode' => true,
            'user_group' => 0,
            'logged_in' => false,
        ];

        $this->assertTrue(is_logged_in());
        $this->assertTrue(is_guest());
    }

    public function test_a_login_after_the_reset_signs_the_account_in(): void
    {
        $this->passwordChangedAt(1, time() - 5);
        $_SESSION = [];

        put_account_in_session($this->account());

        $this->assertTrue(is_logged_in());
        $this->assertGreaterThanOrEqual(time() - 5, $_SESSION['last_login']);
    }

    public function test_a_rejected_session_is_not_still_shown_as_signed_in(): void
    {
        defined('API_ORIGIN') || define('API_ORIGIN', 'api.example.test');
        $changedAt = time() - 60;
        $this->passwordChangedAt(1, $changedAt);
        $_SESSION = [
            'user_id' => 1,
            'player_id' => 5,
            'last_login' => $changedAt - 1,
            'username' => 'ownerusername',
            'user_group' => 1,
            'logged_in' => true,
        ];

        $this->assertFalse(is_logged_in());
        ob_start();
        include PRIVATE_PATH . '/shared/header.php';
        $html = ob_get_clean();

        $this->assertStringNotContainsString('>Logout<', $html);
        $this->assertStringContainsString('Log&nbsp;in', $html);
    }

    public function test_restoring_a_pre_reset_token_on_the_reset_request_does_not_keep_the_browser_signed_in(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_COOKIE['access_token'] = $this->accessToken(time() - 3600);

        // reset-password.php includes header.php (is_logged_in) before resetPassword().
        $this->assertTrue(is_logged_in());
        $this->passwordChangedAt(1, (int) $_SESSION['last_login']);

        $this->assertFalse(is_logged_in());
    }
}
