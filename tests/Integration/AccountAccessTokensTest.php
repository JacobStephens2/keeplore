<?php

namespace Tests\Integration;

use AccountAccessTokens;
use Firebase\JWT\JWT;

require_once __DIR__ . '/MySqlTestCase.php';

final class AccountAccessTokensTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('CREATE TABLE users (id INT PRIMARY KEY, password_changed_at INT UNSIGNED NULL)');
        $this->db->query('INSERT INTO users (id) VALUES (1), (2)');
        require_once PRIVATE_PATH . '/classes/AccountAccessTokens.php';
    }

    public function test_only_a_token_issued_after_the_password_reset_authenticates(): void
    {
        $cutoff = time() - 60;
        $this->db->query('UPDATE users SET password_changed_at = ' . $cutoff . ' WHERE id = 1');

        foreach ([$cutoff - 1, $cutoff, null] as $issuedAt) {
            $token = JWT::encode(['user_id' => 1, 'iat' => $issuedAt], JWT_SECRET, 'HS256');
            $this->assertNull(AccountAccessTokens::authenticate($this->db, $token));
        }
        $withoutIssuedAt = JWT::encode(['user_id' => 1], JWT_SECRET, 'HS256');
        $this->assertNull(AccountAccessTokens::authenticate($this->db, $withoutIssuedAt));
        $token = JWT::encode(['user_id' => 1, 'iat' => $cutoff + 1], JWT_SECRET, 'HS256');
        $this->assertSame(
            ['user_id' => 1, 'issued_at' => $cutoff + 1],
            AccountAccessTokens::authenticate($this->db, $token)
        );
    }

    /** @dataProvider acceptedClaims */
    public function test_optional_claims_and_account_identity_coercion_remain_compatible(array $claims, array $identity): void
    {
        $token = JWT::encode($claims, JWT_SECRET, 'HS256');

        $this->assertSame($identity, AccountAccessTokens::authenticate($this->db, $token));
    }

    public static function acceptedClaims(): array
    {
        return [
            'no optional claims or recorded reset' => [['user_id' => 1], ['user_id' => 1, 'issued_at' => null]],
            'unknown Account' => [['user_id' => 999], ['user_id' => 999, 'issued_at' => null]],
            'zero issued-at stays distinct from null' => [['user_id' => 1, 'iat' => 0], ['user_id' => 1, 'issued_at' => 0]],
            'null issued-at' => [['user_id' => 1, 'iat' => null], ['user_id' => 1, 'issued_at' => null]],
            'numeric string issued-at' => [['user_id' => 1, 'iat' => '123'], ['user_id' => 1, 'issued_at' => 123]],
            'fractional issued-at' => [['user_id' => 1, 'iat' => 123.75], ['user_id' => 1, 'issued_at' => 123]],
            'issuer is not validated' => [['user_id' => 1, 'iss' => 'other.example.test'], ['user_id' => 1, 'issued_at' => null]],
            'string Account id' => [['user_id' => '2'], ['user_id' => 2, 'issued_at' => null]],
            'legacy Account id coercion' => [['user_id' => '2legacy'], ['user_id' => 2, 'issued_at' => null]],
            'zero Account id' => [['user_id' => 0], ['user_id' => 0, 'issued_at' => null]],
        ];
    }

    /** @dataProvider rejectedClaims */
    public function test_jwt_verification_and_missing_account_identity_reject_credentials(array $claims): void
    {
        $token = JWT::encode($claims, JWT_SECRET, 'HS256');

        $this->assertNull(AccountAccessTokens::authenticate($this->db, $token));
    }

    public static function rejectedClaims(): array
    {
        return [
            'missing Account identity' => [['iat' => 1]],
            'null Account identity' => [['user_id' => null]],
            'expired' => [['user_id' => 1, 'exp' => 1]],
            'future not-before' => [['user_id' => 1, 'nbf' => time() + 3600]],
            'future issued-at without not-before' => [['user_id' => 1, 'iat' => time() + 3600]],
        ];
    }

    public function test_malformed_tampered_wrong_secret_and_wrong_algorithm_tokens_are_rejected(): void
    {
        $token = AccountAccessTokens::issue(1, 'login.example.test')['token'];
        [$header, , $signature] = explode('.', $token);
        $tampered = $header . '.' . JWT::urlsafeB64Encode('{"user_id":2}') . '.' . $signature;

        foreach ([
            '',
            'nonsense',
            $tampered,
            JWT::encode(['user_id' => 1], str_repeat('x', 64), 'HS256'),
            JWT::encode(['user_id' => 1], str_repeat('x', 64), 'HS384'),
        ] as $invalid) {
            $this->assertNull(AccountAccessTokens::authenticate($this->db, $invalid));
        }
    }

    public function test_issued_credentials_authenticate_with_their_original_issued_at(): void
    {
        $before = time();
        $issued = AccountAccessTokens::issue(2, 'login.example.test');
        $identity = AccountAccessTokens::authenticate($this->db, $issued['token']);

        $this->assertSame(2, $identity['user_id']);
        $this->assertGreaterThanOrEqual($before, $identity['issued_at']);
        $this->assertLessThanOrEqual(time(), $identity['issued_at']);
    }

    public function test_storage_failures_propagate_instead_of_rejecting_valid_credentials(): void
    {
        $token = AccountAccessTokens::issue(1, 'login.example.test')['token'];
        $this->db->query('DROP TABLE users');

        $this->expectException(\mysqli_sql_exception::class);
        AccountAccessTokens::authenticate($this->db, $token);
    }
}
