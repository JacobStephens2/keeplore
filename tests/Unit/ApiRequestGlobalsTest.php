<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: api_request_from_globals(), the only read of an HTTP API request's
 * credentials and headers. Body reading and JSON decoding are deferred to
 * answer_api_request(); this array carries content_length and body_stream
 * instead of body. Sec-Fetch-Site is sec_fetch_site_header().
 */
class ApiRequestGlobalsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once PRIVATE_PATH . '/api_request.php';
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_CONTENT_LENGTH']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['access_token']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_CONTENT_LENGTH']);
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

    public function test_request_from_globals_defers_body_reading_and_decoding(): void
    {
        json_decode('null');

        $request = api_request_from_globals();

        $this->assertArrayNotHasKey('body', $request);
        $this->assertSame('php://input', $request['body_stream']);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_request_from_globals_carries_content_length_when_present(): void
    {
        $this->assertNull(api_request_from_globals()['content_length']);

        $_SERVER['CONTENT_LENGTH'] = '';
        $this->assertNull(api_request_from_globals()['content_length']);

        $_SERVER['HTTP_CONTENT_LENGTH'] = '512';
        $this->assertSame('512', api_request_from_globals()['content_length']);

        $_SERVER['CONTENT_LENGTH'] = '262145';
        $this->assertSame('262145', api_request_from_globals()['content_length']);
    }
}
