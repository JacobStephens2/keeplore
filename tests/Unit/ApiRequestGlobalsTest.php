<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: api_request_from_globals(), the only read of an HTTP API request's
 * credentials. Sec-Fetch-Site is sec_fetch_site_header(). The request
 * array carries that header for the handler, or null when the browser
 * did not send it.
 */
class ApiRequestGlobalsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once PRIVATE_PATH . '/api_request.php';
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['access_token']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['access_token']);
    }

    public function test_the_request_carries_sec_fetch_site(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $request = api_request_from_globals();

        $this->assertSame('cross-site', $request['sec_fetch_site']);
    }

    public function test_a_request_without_sec_fetch_site_carries_null(): void
    {
        $request = api_request_from_globals();

        $this->assertArrayHasKey('sec_fetch_site', $request);
        $this->assertNull($request['sec_fetch_site']);
    }

    public function test_present_empty_invalid_and_array_cookies_are_distinct_from_absent_credentials(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ak_example';
        $this->assertNull(api_request_from_globals()['access_token']);

        foreach (['', 'invalid', ['not-a-token']] as $cookie) {
            $_COOKIE['access_token'] = $cookie;
            $request = api_request_from_globals();

            $this->assertSame(is_array($cookie) ? '' : $cookie, $request['access_token']);
            $this->assertSame('Bearer ak_example', $request['authorization']);
        }
    }
}
