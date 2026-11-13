<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/Accounts.php';

/** Account login credentials, shared by login, session restoration and HTTP requests. */
final class AccountAccessTokens
{
    /**
     * Issue a credential for 24 hours, or 30 days with Remember me.
     * The expiry and lifetime also govern the login cookie and PHP session.
     *
     * @return array{token: string, issued_at: int, expires_at: int, lifetime: int}
     */
    public static function issue(int $accountId, string $issuer, bool $remember = false): array
    {
        $issuedAt = time();
        $lifetime = $remember ? 2592000 : 86400;
        $expiresAt = $issuedAt + $lifetime;
        return [
            'token' => JWT::encode([
                'iat' => $issuedAt,
                'iss' => $issuer,
                'nbf' => $issuedAt,
                'exp' => $expiresAt,
                'user_id' => $accountId,
            ], JWT_SECRET, 'HS256'),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'lifetime' => $lifetime,
        ];
    }

    /**
     * Authenticate the signed credential under the JWT library's present-claim
     * rules and Accounts' password-reset cutoff. Optional claims stay optional;
     * a missing Account is not rejected by the cutoff. Session restoration must
     * still find the Account. Storage failures propagate to the caller.
     *
     * @return array{user_id: int, issued_at: ?int}|null
     */
    public static function authenticate(mysqli $db, string $token): ?array
    {
        try {
            $claims = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        } catch (Exception $invalid) {
            return null;
        }
        if (!isset($claims->user_id)) {
            return null;
        }
        $accountId = (int) $claims->user_id;
        $issuedAt = $claims->iat ?? null;
        if (!Accounts::issuedAtAuthenticates($db, $accountId, $issuedAt)) {
            return null;
        }
        return [
            'user_id' => $accountId,
            'issued_at' => $issuedAt === null ? null : (int) $issuedAt,
        ];
    }
}
