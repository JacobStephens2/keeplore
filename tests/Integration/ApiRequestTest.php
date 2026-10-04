<?php

namespace Tests\Integration;

use AgentKeys;
use ApiCaller;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * Seam: answer_api_request(), which answers every HTTP API request. It
 * checks the API rate limit, then the credential, then the method, and
 * only then runs the endpoint's handler with the API caller and the
 * request. The result is the status code and the response body.
 */
final class ApiRequestTest extends TestCase
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
        $this->db->query('CREATE TABLE users (id INT PRIMARY KEY)');
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

    /** A handler map whose GET answers 200 and counts its runs. */
    private function counted(int &$runs): array
    {
        return ['GET' => function () use (&$runs) {
            $runs++;
            return [200, ['ok' => true]];
        }];
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
}
