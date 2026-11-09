<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: run_guest_or_logout(), the request Browse as guest, Logout, and
 * Exit guest mode share. It reads Sec-Fetch-Site. cross-site is the
 * Daily email's refusal: 403, a message, and the session left as it was.
 * same-site, same-origin, and none still run the action. Exit guest mode
 * is logout while the session is already a guest.
 */
final class CrossSiteGuestLogoutTest extends TestCase
{
    protected function setUp(): void
    {
        defined('ARTIFACTS_DOMAIN') || define('ARTIFACTS_DOMAIN', '');
        defined('COOKIE_SECURE') || define('COOKIE_SECURE', false);
        defined('DEMO_USER_ID') || define('DEMO_USER_ID', 99);
        require_once PRIVATE_PATH . '/auth_functions.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = $this->accountSession();
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    private function fetchSite(?string $fetchSite): void
    {
        if ($fetchSite === null) {
            unset($_SERVER['HTTP_SEC_FETCH_SITE']);
            return;
        }
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $fetchSite;
    }

    /** An Account session, not a guest. */
    private function accountSession(): array
    {
        return [
            'user_id' => 1,
            'logged_in' => true,
            'username' => 'ownerusername',
            'user_group' => 1,
        ];
    }

    /** A guest session, as start_guest_session() leaves it. */
    private function guestSession(): array
    {
        return [
            'user_id' => 99,
            'guest_mode' => true,
            'FullName' => 'Guest',
            'username' => 'Guest',
            'logged_in' => false,
            'user_group' => 0,
        ];
    }

    public function test_a_cross_site_request_does_not_browse_as_guest(): void
    {
        $this->fetchSite('cross-site');

        $refusal = run_guest_or_logout('guest');

        $this->assertSame([403, ['message' => 'A cross-site request cannot browse as guest.']], $refusal);
        $this->assertSame($this->accountSession(), $_SESSION);
        $this->assertFalse(is_guest());
    }

    public function test_a_cross_site_request_does_not_log_out(): void
    {
        $this->fetchSite('cross-site');

        $refusal = run_guest_or_logout('logout');

        $this->assertSame([403, ['message' => 'A cross-site request cannot log out.']], $refusal);
        $this->assertSame($this->accountSession(), $_SESSION);
    }

    public function test_a_cross_site_request_does_not_exit_guest_mode(): void
    {
        $_SESSION = $this->guestSession();
        $this->fetchSite('cross-site');

        $refusal = run_guest_or_logout('logout');

        $this->assertSame([403, ['message' => 'A cross-site request cannot exit guest mode.']], $refusal);
        $this->assertSame($this->guestSession(), $_SESSION);
        $this->assertTrue(is_guest());
    }

    public static function requestsThatStillRun(): array
    {
        return [
            'same-site' => ['same-site'],
            'same-origin' => ['same-origin'],
            'none' => ['none'],
            'no header' => [null],
        ];
    }

    /** @dataProvider requestsThatStillRun */
    public function test_a_request_that_is_not_cross_site_still_browses_as_guest(?string $fetchSite): void
    {
        $this->fetchSite($fetchSite);

        $refusal = run_guest_or_logout('guest');

        $this->assertNull($refusal);
        $this->assertTrue(is_guest());
        $this->assertSame(99, $_SESSION['user_id']);
        $this->assertFalse($_SESSION['logged_in']);
        $this->assertSame('Guest', $_SESSION['username']);
    }

    /** @dataProvider requestsThatStillRun */
    public function test_a_request_that_is_not_cross_site_still_logs_out(?string $fetchSite): void
    {
        $this->fetchSite($fetchSite);

        $refusal = run_guest_or_logout('logout');

        $this->assertNull($refusal);
        $this->assertSame([], $_SESSION);
        $this->assertFalse(is_guest());
    }

    /** @dataProvider requestsThatStillRun */
    public function test_a_request_that_is_not_cross_site_still_exits_guest_mode(?string $fetchSite): void
    {
        $_SESSION = $this->guestSession();
        $this->fetchSite($fetchSite);

        $refusal = run_guest_or_logout('logout');

        $this->assertNull($refusal);
        $this->assertSame([], $_SESSION);
        $this->assertFalse(is_guest());
    }

    public function test_a_login_request_is_not_guest_or_logout(): void
    {
        $this->fetchSite('cross-site');

        $refusal = run_guest_or_logout('');

        $this->assertNull($refusal);
        $this->assertSame($this->accountSession(), $_SESSION);
    }

    public function test_a_header_that_is_not_a_string_is_not_cross_site(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = ['cross-site'];

        $refusal = run_guest_or_logout('logout');

        $this->assertNull($refusal);
        $this->assertSame([], $_SESSION);
    }
}
