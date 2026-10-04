<?php

namespace Tests\Integration;

use AgentKeys;
use ApiCaller;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * Seam: ApiCaller, who an HTTP API request is, from the credentials it
 * carries, and whose data it may act for. A session cookie wins, then a
 * Bearer agent key, then the master key, with no fall-through after a
 * failed check. A session and an agent key act for their own user; the
 * master key acts for the existing user it names. Only an agent key is
 * refused outside reads and the kept toggle (ADR-0002).
 */
final class ApiCallerTest extends TestCase
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
        require_once PRIVATE_PATH . '/classes/ApiCaller.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    /** A session token for $userId, as logging in signs it, expiring $expiresIn seconds from now. */
    private function sessionToken(int $userId, int $expiresIn = 3600, string $secret = JWT_SECRET): string
    {
        $now = time();
        return JWT::encode(['iat' => $now, 'nbf' => $now, 'exp' => $now + $expiresIn, 'user_id' => $userId], $secret, 'HS256');
    }

    private function agentKeyHeader(int $userId): string
    {
        return 'Bearer ' . (new AgentKeys($this->db, $userId))->issue('test agent')['token'];
    }

    private function fromCredentials(?string $accessToken, ?string $authorization): ?ApiCaller
    {
        return ApiCaller::fromCredentials($this->db, $accessToken, $authorization);
    }

    public function test_a_valid_session_token_acts_for_its_user(): void
    {
        $caller = $this->fromCredentials($this->sessionToken(2), null);

        $this->assertSame(2, $caller->owner(1));
        $this->assertNull($caller->agentKeyRefusal());
    }

    public function test_an_expired_or_tampered_session_token_gives_no_caller_even_with_a_valid_agent_key(): void
    {
        $header = $this->agentKeyHeader(1);
        [$header64, $payload64, $signature] = explode('.', $this->sessionToken(1));
        $tampered = $header64 . '.' . rtrim(strtr(base64_encode('{"user_id":2,"exp":' . (time() + 3600) . '}'), '+/', '-_'), '=') . '.' . $signature;

        foreach ([$this->sessionToken(1, -60), $tampered, $this->sessionToken(1, 3600, str_repeat('x', 40)), '', 'nonsense'] as $token) {
            $this->assertNull($this->fromCredentials($token, $header), $token);
            $this->assertNull($this->fromCredentials($token, ARTIFACTS_API_KEY), $token);
        }
    }

    public function test_an_issued_agent_key_acts_for_its_user_and_is_refused_outside_reads_and_the_kept_toggle(): void
    {
        $caller = $this->fromCredentials(null, $this->agentKeyHeader(2));

        $this->assertSame(2, $caller->owner(1));
        $this->assertSame(
            [403, ['authenticated' => true, 'message' => 'Agent keys permit reads plus the kept toggle only.']],
            $caller->agentKeyRefusal()
        );
    }

    public function test_the_bearer_scheme_is_case_insensitive(): void
    {
        $token = substr($this->agentKeyHeader(1), strlen('Bearer '));

        $this->assertSame(1, $this->fromCredentials(null, 'bearer ' . $token)->owner());
    }

    public function test_a_revoked_or_unknown_agent_key_or_an_empty_bearer_token_gives_no_caller(): void
    {
        $keys = new AgentKeys($this->db, 1);
        $revoked = $keys->issue('revoked');
        $keys->revoke($revoked['id']);

        foreach (['Bearer ' . $revoked['token'], 'Bearer ak_unknown', 'Bearer ', 'Bearer    ', 'Bearer ' . ARTIFACTS_API_KEY] as $header) {
            $this->assertNull($this->fromCredentials(null, $header), $header);
        }
    }

    public function test_the_master_key_acts_for_a_named_existing_user(): void
    {
        $master = $this->fromCredentials(null, ARTIFACTS_API_KEY);

        $this->assertSame(2, $master->owner(2));
        $this->assertNull($master->owner('9'));
        $this->assertNull($master->agentKeyRefusal());
    }

    public function test_a_wrong_master_key_or_no_credentials_give_no_caller(): void
    {
        $this->assertNull($this->fromCredentials(null, ARTIFACTS_API_KEY . 'x'));
        $this->assertNull($this->fromCredentials(null, ''));
        $this->assertNull($this->fromCredentials(null, null));
    }

    public function test_a_session_and_an_agent_key_act_for_their_own_user_whatever_is_requested(): void
    {
        foreach ([ApiCaller::session($this->db, 1), ApiCaller::agentKey($this->db, 1)] as $caller) {
            $this->assertSame(1, $caller->owner());
            $this->assertSame(1, $caller->owner(2));
            $this->assertSame(1, $caller->owner('9'));
            $this->assertSame(1, $caller->owner('nope'));
        }
    }

    public function test_the_master_key_acts_for_the_existing_user_it_names(): void
    {
        $master = ApiCaller::masterKey($this->db);

        $this->assertSame(2, $master->owner(2));
        $this->assertSame(2, $master->owner('2'));
    }

    public function test_the_master_key_acts_for_no_one_when_the_named_user_is_missing_malformed_or_unknown(): void
    {
        $master = ApiCaller::masterKey($this->db);

        foreach ([null, '', '0', '-1', '1.5', 'two', [2], '9'] as $requested) {
            $this->assertNull($master->owner($requested), var_export($requested, true));
        }
    }

    public function test_only_an_agent_key_is_refused(): void
    {
        $this->assertSame(
            [403, ['authenticated' => true, 'message' => 'Agent keys permit reads plus the kept toggle only.']],
            ApiCaller::agentKey($this->db, 1)->agentKeyRefusal()
        );
        $this->assertNull(ApiCaller::session($this->db, 1)->agentKeyRefusal());
        $this->assertNull(ApiCaller::masterKey($this->db)->agentKeyRefusal());
    }
}
