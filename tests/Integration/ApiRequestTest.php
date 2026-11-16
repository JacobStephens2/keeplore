<?php

namespace Tests\Integration;

use AgentKeys;
use ApiCaller;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalPhpServer;

require_once __DIR__ . '/../Support/LocalPhpServer.php';

/**
 * Seam: answer_api_request(), which answers every HTTP API request. It
 * checks the API rate limit, then the credential, then the method, then
 * the 256 KiB request body limit (before decoding a deferred stream), and
 * only then runs the endpoint's handler with the API caller and the
 * request. The result is the status code and the response body.
 */
final class ApiRequestTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    /** @var list<resource> */
    private array $openStreams = [];

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
        $this->db->query('CREATE TABLE users (id INT PRIMARY KEY, password_changed_at INT UNSIGNED NULL)');
        $this->db->query('INSERT INTO users (id) VALUES (1), (2)');
        $this->db->query('CREATE TABLE agent_api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            agent_name VARCHAR(100) NOT NULL,
            key_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL DEFAULT NULL,
            revoked_at DATETIME NULL DEFAULT NULL
        )');
        require_once PRIVATE_PATH . '/api_request.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->openStreams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->openStreams = [];
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    /** [access token, Authorization header] for a session of $userId's, signed as logging in signs it. */
    private function session(int $userId = 1, int $expiresIn = 3600): array
    {
        $now = time();
        return [JWT::encode(['iat' => $now, 'nbf' => $now, 'exp' => $now + $expiresIn, 'user_id' => $userId], JWT_SECRET, 'HS256'), null];
    }

    /** [access token, Authorization header] for a newly issued agent key of $userId's. */
    private function agentKey(int $userId = 1): array
    {
        return [null, 'Bearer ' . (new AgentKeys($this->db, $userId))->issue('test agent')['token']];
    }

    /** A request carrying $credentials ([access token, Authorization header]), a session of user 1's by default. */
    private function request(string $method = 'GET', ?array $credentials = null, array $query = [], $body = null): array
    {
        [$accessToken, $authorization] = $credentials ?? $this->session();
        return [
            'method' => $method,
            'query' => $query,
            'body' => $body,
            'access_token' => $accessToken,
            'authorization' => $authorization,
        ];
    }

    /** A handler map whose $methods answer 200 and count their runs. */
    private function counted(int &$runs, array $methods = ['GET']): array
    {
        $handlers = [];
        foreach ($methods as $method) {
            $handlers[$method] = function () use (&$runs) {
                $runs++;
                return [200, ['ok' => true]];
            };
        }
        return $handlers;
    }

    /** Fills this IP's API rate limit window. */
    private function fillTheWindow(): void
    {
        $limiter = new \RateLimiter($this->db);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordAttempt('api');
        }
    }

    public function test_a_handler_runs_with_the_caller_and_the_request(): void
    {
        $request = $this->request('POST', null, ['id' => '7'], (object) ['user_id' => 2]);
        $seen = null;

        $answer = answer_api_request($this->db, 'test', ['POST' => function (ApiCaller $caller, array $request) use (&$seen) {
            $seen = [$caller->owner($request['body']->user_id), $caller->agentKeyRefusal(), $request['query']];
            return [201, ['message' => 'Made.']];
        }], $request);

        $this->assertEquals([201, (object) ['message' => 'Made.']], $answer);
        $this->assertSame([1, null, ['id' => '7']], $seen);
    }

    public function test_an_agent_key_or_the_master_key_reaches_the_handler_as_its_caller(): void
    {
        $seen = [];
        $handlers = ['GET' => function (ApiCaller $caller) use (&$seen) {
            $seen[] = [$caller->owner(2), $caller->agentKeyRefusal() !== null];
            return [200, []];
        }];

        answer_api_request($this->db, 'test', $handlers, $this->request('GET', $this->agentKey(1)));
        answer_api_request($this->db, 'test', $handlers, $this->request('GET', [null, ARTIFACTS_API_KEY]));

        $this->assertSame([[1, true], [2, false]], $seen);
    }

    public function test_the_body_is_always_an_object(): void
    {
        [$status, $body] = answer_api_request($this->db, 'test', ['DELETE' => fn () => [200, []]], $this->request('DELETE'));

        $this->assertSame(200, $status);
        $this->assertSame('{}', json_encode($body));
    }

    public function test_a_bad_credential_or_none_gets_401_and_the_handler_does_not_run(): void
    {
        $runs = 0;
        $credentials = [
            $this->session(1, -60),
            ['not-a-token', ARTIFACTS_API_KEY],
            [null, 'Bearer ak_unknown'],
            [null, ARTIFACTS_API_KEY . 'x'],
            [null, null],
        ];
        foreach ($credentials as $credential) {
            [$status, $body] = answer_api_request($this->db, 'test', $this->counted($runs), $this->request('GET', $credential));

            $this->assertSame(401, $status);
            $this->assertEquals((object) ['authenticated' => false, 'message' => 'You have not been authenticated'], $body);
        }
        $this->assertSame(0, $runs);
    }

    public function test_an_unsupported_method_gets_405_naming_the_methods(): void
    {
        $handlers = ['GET' => fn () => [200, []], 'POST' => fn () => [201, []], 'DELETE' => fn () => [200, []]];

        [$status, $body] = answer_api_request($this->db, 'test', $handlers, $this->request('PUT'));

        $this->assertSame(405, $status);
        $this->assertEquals((object) ['message' => 'Method not allowed. Supported methods: GET, POST, DELETE'], $body);
    }

    public function test_the_credential_is_checked_before_the_method(): void
    {
        [$status] = answer_api_request($this->db, 'test', ['GET' => fn () => [200, []]], $this->request('PUT', [null, null]));

        $this->assertSame(401, $status);
    }

    public function test_429_once_the_window_holds_the_limit_before_the_credential_is_checked(): void
    {
        $runs = 0;
        $this->fillTheWindow();

        [$status, $body] = answer_api_request($this->db, 'test', $this->counted($runs), $this->request());
        [$unauthenticated] = answer_api_request($this->db, 'test', $this->counted($runs), $this->request('GET', [null, null]));

        $this->assertSame(429, $status);
        $this->assertEquals((object) ['message' => 'Rate limit exceeded. Please try again later.'], $body);
        $this->assertSame(429, $unauthenticated);
        $this->assertSame(0, $runs);
    }

    public function test_each_request_counts_toward_the_limit(): void
    {
        $runs = 0;
        for ($i = 0; $i < 60; $i++) {
            answer_api_request($this->db, 'test', $this->counted($runs), $this->request());
        }

        $this->assertSame(429, answer_api_request($this->db, 'test', $this->counted($runs), $this->request())[0]);
        $this->assertSame(60, $runs);
    }

    public function test_an_unmetered_endpoint_is_exempt_from_the_rate_limit(): void
    {
        $runs = 0;
        $this->fillTheWindow();

        [$status] = answer_api_request($this->db, 'test', $this->counted($runs), $this->request(), metered: false);

        $this->assertSame(200, $status);
        $this->assertSame(1, $runs);
        $this->assertSame('60', $this->db->query('SELECT COUNT(*) FROM rate_limits')->fetch_row()[0]);
    }

    public function test_a_metered_request_is_logged_under_its_endpoint_and_an_unmetered_one_is_not(): void
    {
        $metered = 'metered-' . bin2hex(random_bytes(4));
        $unmetered = 'unmetered-' . bin2hex(random_bytes(4));

        answer_api_request($this->db, $metered, ['GET' => fn () => [200, []]], $this->request());
        answer_api_request($this->db, $unmetered, ['GET' => fn () => [200, []]], $this->request(), metered: false);

        $log = (string) @file_get_contents(PROJECT_PATH . '/logs/app.log');
        $this->assertStringContainsString('"endpoint":"' . $metered . '"', $log);
        $this->assertStringNotContainsString($unmetered, $log);
    }

    public function test_an_unmetered_endpoint_still_needs_a_credential_and_a_supported_method(): void
    {
        $handlers = ['POST' => fn () => [200, []]];

        $this->assertSame(401, answer_api_request($this->db, 'test', $handlers, $this->request('POST', [null, null]), metered: false)[0]);
        $this->assertSame(405, answer_api_request($this->db, 'test', $handlers, $this->request('GET'), metered: false)[0]);
    }

    /** An open in-memory stream positioned at byte 0 holding $raw. */
    private function bodyStream(string $raw)
    {
        $stream = fopen('php://memory', 'r+b');
        $this->assertIsResource($stream);
        $this->openStreams[] = $stream;
        if ($raw !== '') {
            fwrite($stream, $raw);
            rewind($stream);
        }
        return $stream;
    }

    /** A deferred-body request without a pre-built body entry. */
    private function streamRequest(
        string $method = 'GET',
        $stream = null,
        ?string $contentLength = null,
        ?array $credentials = null,
        array $query = []
    ): array {
        [$accessToken, $authorization] = $credentials ?? $this->session();
        return [
            'method' => $method,
            'query' => $query,
            'content_length' => $contentLength,
            'body_stream' => $stream ?? $this->bodyStream(''),
            'access_token' => $accessToken,
            'authorization' => $authorization,
        ];
    }

    public function test_streamed_json_bodies_reach_get_post_and_put_handlers(): void
    {
        $seen = [];
        $handlers = [];
        foreach (['GET', 'POST', 'PUT'] as $method) {
            $handlers[$method] = function (ApiCaller $caller, array $request) use ($method, &$seen) {
                $seen[$method] = $request['body']->item ?? null;
                return [200, ['method' => $method]];
            };
        }

        foreach (['GET', 'POST', 'PUT'] as $method) {
            $stream = $this->bodyStream(json_encode(['item' => $method . '-value']));
            [$status, $body] = answer_api_request($this->db, 'test', $handlers, $this->streamRequest($method, $stream));

            $this->assertSame(200, $status);
            $this->assertSame($method, $body->method);
        }

        $this->assertSame(['GET' => 'GET-value', 'POST' => 'POST-value', 'PUT' => 'PUT-value'], $seen);
    }

    public function test_429_401_and_405_do_not_read_or_decode_the_request_body(): void
    {
        $handlers = ['POST' => fn () => [200, []]];

        $this->assertDeferredRejectionLeavesBodyUnreadAndUndecoded(401, $handlers, 'POST', [null, null]);
        $this->assertDeferredRejectionLeavesBodyUnreadAndUndecoded(401, $handlers, 'POST', [null, null], metered: false);
        $this->assertDeferredRejectionLeavesBodyUnreadAndUndecoded(405, $handlers, 'PUT');
        $this->assertDeferredRejectionLeavesBodyUnreadAndUndecoded(405, $handlers, 'PUT', [null, ARTIFACTS_API_KEY], metered: false);

        $this->fillTheWindow();
        $this->assertDeferredRejectionLeavesBodyUnreadAndUndecoded(429, $handlers, 'POST');
    }

    private function assertDeferredRejectionLeavesBodyUnreadAndUndecoded(
        int $expectedStatus,
        array $handlers,
        string $method,
        ?array $credentials = null,
        bool $metered = true
    ): void {
        $stream = $this->bodyStream('{invalid');
        if (!$metered) {
            json_decode('[[', true, 1);
        }
        [$status] = answer_api_request(
            $this->db,
            'test',
            $handlers,
            $this->streamRequest($method, $stream, null, $credentials),
            metered: $metered
        );

        $this->assertSame($expectedStatus, $status);
        $this->assertSame(0, ftell($stream));
        if ($metered) {
            $this->assertNotSame(JSON_ERROR_SYNTAX, json_last_error());
        } else {
            $this->assertSame(JSON_ERROR_DEPTH, json_last_error());
        }
    }

    public function test_oversized_content_length_returns_413_without_reading_or_decoding(): void
    {
        $runs = 0;
        $handlers = $this->counted($runs, ['GET', 'POST', 'PUT']);

        foreach (['GET', 'POST', 'PUT'] as $method) {
            foreach (['262145', '99999999999999999999999999'] as $contentLength) {
                $this->assertTooLargeWithoutDecoding($method, '{invalid', $contentLength, 0, $handlers, "$method $contentLength");
            }
        }
        $this->assertSame(0, $runs);
    }

    public function test_oversized_streamed_body_returns_413_without_decoding_after_limit_plus_one_bytes(): void
    {
        $runs = 0;
        $handlers = $this->counted($runs, ['GET', 'POST', 'PUT']);

        $cases = [
            'exact limit plus one' => ['{invalid' . str_repeat(' ', 262145 - 8), null],
            'large stream with understated content-length' => ['{invalid' . str_repeat(' ', 300000), '10'],
        ];
        foreach (['GET', 'POST', 'PUT'] as $method) {
            foreach ($cases as $label => [$payload, $contentLength]) {
                $this->assertTooLargeWithoutDecoding($method, $payload, $contentLength, 262145, $handlers, "$method $label");
            }
        }
        $this->assertSame(0, $runs);
    }

    private function assertTooLargeWithoutDecoding(
        string $method,
        string $payload,
        ?string $contentLength,
        int $expectedBytesRead,
        array $handlers,
        string $label
    ): void {
        $stream = $this->bodyStream($payload);
        json_decode('[[', true, 1);
        [$status, $body] = answer_api_request(
            $this->db,
            'test',
            $handlers,
            $this->streamRequest($method, $stream, $contentLength, [null, ARTIFACTS_API_KEY]),
            metered: false
        );

        $this->assertSame(413, $status, $label);
        $this->assertEquals((object) ['message' => 'Request body too large.'], $body);
        $this->assertSame($expectedBytesRead, ftell($stream));
        $this->assertSame(JSON_ERROR_DEPTH, json_last_error());
    }

    public function test_body_exactly_at_256_kib_limit_succeeds_across_get_post_and_put(): void
    {
        $prefix = '{"pad":"';
        $suffix = '","ok":true}';
        $payload = $prefix . str_repeat('a', 262144 - strlen($prefix) - strlen($suffix)) . $suffix;
        $this->assertSame(262144, strlen($payload));

        $tempPath = tempnam(sys_get_temp_dir(), 'keeplore-body-');
        $this->assertIsString($tempPath);
        file_put_contents($tempPath, $payload);
        try {
            $handlers = [];
            foreach (['GET', 'POST', 'PUT'] as $method) {
                $handlers[$method] = fn (ApiCaller $caller, array $request) => [200, [
                    'method' => $method,
                    'ok' => $request['body']->ok ?? false,
                    'pad_len' => strlen((string) ($request['body']->pad ?? '')),
                ]];
            }

            foreach (['GET', 'POST', 'PUT'] as $method) {
                foreach ([['262144', $this->bodyStream($payload)], [null, $tempPath]] as [$contentLength, $source]) {
                    [$status, $body] = answer_api_request(
                        $this->db,
                        'test',
                        $handlers,
                        $this->streamRequest($method, $source, $contentLength)
                    );

                    $this->assertSame(200, $status, $method);
                    $this->assertSame($method, $body->method);
                    $this->assertTrue($body->ok);
                    $this->assertSame(262144 - strlen($prefix) - strlen($suffix), $body->pad_len);
                }
            }
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_http_requests_enforce_order_and_256_kib_limit_across_methods_and_chunked_bodies(): void
    {
        $server = new LocalPhpServer($this->httpApiRouter());
        try {
            $baseHeaders = ['Authorization: ' . ARTIFACTS_API_KEY, 'Content-Type: application/json'];

            // Valid bodies within limit (including exact 262,144 bytes) across GET, POST, PUT
            $prefix = '{"pad":"';
            $suffix = '","tag":"ok"}';
            $maxBody = $prefix . str_repeat('b', 262144 - strlen($prefix) - strlen($suffix)) . $suffix;
            $this->assertSame(262144, strlen($maxBody));

            foreach (['GET', 'POST', 'PUT'] as $method) {
                $small = $server->request('/', $baseHeaders, $method, '{"tag":"small"}');
                $this->assertSame(200, $small['status'], $small['body']);
                $this->assertSame(['method' => $method, 'tag' => 'small', 'len' => null], json_decode($small['body'], true));

                $exact = $server->request('/', $baseHeaders, $method, $maxBody);
                $this->assertSame(200, $exact['status'], $exact['body']);
                $this->assertSame('ok', json_decode($exact['body'], true)['tag']);
            }

            // Oversized Content-Length (262,145 bytes) across GET, POST, PUT -> 413
            $oversizedBody = '{"pad":"' . str_repeat('c', 262145 - 10) . '"}';
            $this->assertSame(262145, strlen($oversizedBody));
            foreach (['GET', 'POST', 'PUT'] as $method) {
                $rejected = $server->request('/', $baseHeaders, $method, $oversizedBody);
                $this->assertSame(413, $rejected['status'], $rejected['body']);
                $this->assertSame('{"message":"Request body too large."}', $rejected['body']);
            }

            // Oversized chunked request (no Content-Length) -> 413; within-limit chunked -> 200
            $chunkedOk = $server->requestChunked('/', $baseHeaders, 'POST', '{"tag":"chunked"}');
            $this->assertSame(200, $chunkedOk['status'], $chunkedOk['body']);
            $this->assertSame('chunked', json_decode($chunkedOk['body'], true)['tag']);

            $chunkedTooLarge = $server->requestChunked('/', $baseHeaders, 'POST', $oversizedBody);
            $this->assertSame(413, $chunkedTooLarge['status'], $chunkedTooLarge['body']);
            $this->assertSame('{"message":"Request body too large."}', $chunkedTooLarge['body']);

            // 401 and 405 still happen before 413 even with an oversized body
            $unauth = $server->request('/', ['Content-Type: application/json'], 'POST', $oversizedBody);
            $this->assertSame(401, $unauth['status'], $unauth['body']);

            $wrongMethod = $server->request('/', $baseHeaders, 'DELETE', $oversizedBody);
            $this->assertSame(405, $wrongMethod['status'], $wrongMethod['body']);

            // 429 happens before 413 even with an oversized body
            $this->fillTheWindow();
            $rateLimited = $server->request('/', $baseHeaders, 'POST', $oversizedBody);
            $this->assertSame(429, $rateLimited['status'], $rateLimited['body']);
        } finally {
            $server->stop();
        }
    }

    private function httpApiRouter(): string
    {
        $autoload = var_export(PROJECT_PATH . '/vendor/autoload.php', true);
        $apiRequest = var_export(PRIVATE_PATH . '/api_request.php', true);
        $databaseName = var_export($this->databaseName, true);
        $host = var_export(getenv('KEEPLORE_TEST_DB_HOST') ?: '127.0.0.1', true);
        $user = var_export(getenv('KEEPLORE_TEST_DB_USER') ?: 'root', true);
        $pass = var_export(getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '', true);
        $port = (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306);

        return <<<PHP
<?php
defined('PROJECT_PATH') || define('PROJECT_PATH', getcwd());
defined('JWT_SECRET') || define('JWT_SECRET', 'test-jwt-secret-of-at-least-thirty-two-bytes');
defined('ARTIFACTS_API_KEY') || define('ARTIFACTS_API_KEY', 'test-master-key');
require_once $autoload;
require_once $apiRequest;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
\$db = new mysqli($host, $user, $pass, $databaseName, $port);
\$handler = fn (ApiCaller \$caller, array \$request) => [200, [
    'method' => \$request['method'],
    'tag' => \$request['body']->tag ?? null,
    'len' => isset(\$request['body']->pad) ? strlen((string) \$request['body']->pad) : null,
]];

emit_api_response(answer_api_request(\$db, 'http-test', [
    'GET' => \$handler,
    'POST' => \$handler,
    'PUT' => \$handler,
], api_request_from_globals()));
return true;
PHP;
    }
}
