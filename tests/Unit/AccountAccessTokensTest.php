<?php

namespace Tests\Unit;

use AccountAccessTokens;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class AccountAccessTokensTest extends TestCase
{
    public function test_login_issues_a_signed_credential_and_matching_expiry_metadata(): void
    {
        require_once PRIVATE_PATH . '/classes/AccountAccessTokens.php';

        foreach ([false => 86400, true => 2592000] as $remember => $lifetime) {
            $before = time();
            $issued = AccountAccessTokens::issue(42, 'login.example.test', (bool) $remember);
            $after = time();
            $claims = JWT::decode($issued['token'], new Key(JWT_SECRET, 'HS256'));

            $this->assertSame(42, $claims->user_id);
            $this->assertSame('login.example.test', $claims->iss);
            $this->assertGreaterThanOrEqual($before, $claims->iat);
            $this->assertLessThanOrEqual($after, $claims->iat);
            $this->assertSame($claims->iat, $claims->nbf);
            $this->assertSame($lifetime, $issued['lifetime']);
            $this->assertSame($lifetime, $claims->exp - $claims->iat);
            $this->assertSame($claims->exp, $issued['expires_at']);
        }
    }
}
