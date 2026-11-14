<?php

namespace Tests\Unit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalPhpServer;

require_once __DIR__ . '/../Support/LocalPhpServer.php';

/**
 * Seam: the access_token Set-Cookie from log_in_user and from
 * expire_access_token, the call log_out uses. The cookie is SameSite=Strict,
 * and it keeps its expiry, path, domain, secure flag and HttpOnly.
 *
 * PHP's CLI SAPI does not record setcookie() in headers_list(), so this
 * watches a real HTTP response.
 */
class AccessTokenCookieTest extends TestCase
{
    private ?LocalPhpServer $server = null;

    protected function setUp(): void
    {
        $this->server = new LocalPhpServer($this->routerSource());
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function test_logging_in_sets_a_samesite_strict_access_token(): void
    {
        $day = $this->accessTokenCookie($this->request('login')['headers']);
        $this->assertNotNull($day);
        $this->assertAccessTokenCookie($day, 86400);

        $month = $this->accessTokenCookie($this->request('login&remember=1')['headers']);
        $this->assertNotNull($month);
        $this->assertAccessTokenCookie($month, 2592000);
    }

    public function test_expiring_the_access_token_stays_samesite_strict(): void
    {
        $cookie = $this->accessTokenCookie($this->request('expire')['headers']);
        $this->assertNotNull($cookie);
        $this->assertAccessTokenCookie($cookie, 0);
    }

    public function test_login_cookie_and_php_session_use_the_issued_token_window(): void
    {
        foreach (['login' => 86400, 'login&remember=1' => 2592000] as $mode => $lifetime) {
            $response = $this->request($mode);
            $cookie = $this->accessTokenCookie($response['headers']);
            preg_match('/^access_token=([^;]+)/', $cookie, $token);
            preg_match('/expires=([^;]+)/i', $cookie, $expires);
            $claims = JWT::decode(rawurldecode($token[1]), new Key(JWT_SECRET, 'HS256'));
            $session = json_decode($response['body'], true);

            $this->assertSame($claims->exp, strtotime($expires[1]));
            $this->assertSame('127.0.0.1', $claims->iss);
            $this->assertSame($lifetime, $session['gc_lifetime']);
            $this->assertSame($lifetime, $session['cookie_lifetime']);
            $this->assertTrue($session['regenerated']);
            $this->assertSame('kept', $session['existing_field']);
            $this->assertSame(1, $session['user_id']);
        }
    }

    private function assertAccessTokenCookie(string $cookie, int $maxAge): void
    {
        $this->assertStringContainsString('SameSite=Strict', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('path=/', $cookie);
        $this->assertStringContainsString('domain=keeplore.app', $cookie);
        $this->assertStringContainsString('; secure;', $cookie);
        $this->assertStringContainsString('Max-Age=' . $maxAge . ';', $cookie);
    }

    /** @return array{status: int, headers: string, body: string} */
    private function request(string $mode): array
    {
        $response = $this->server->request('/?mode=' . $mode);
        $this->assertSame(200, $response['status'], $response['body']);
        return $response;
    }

    private function accessTokenCookie(string $headers): ?string
    {
        foreach (preg_split("/\r\n/", $headers) as $line) {
            if (stripos($line, 'Set-Cookie: access_token=') === 0) {
                return substr($line, strlen('Set-Cookie: '));
            }
        }
        return null;
    }

    private function routerSource(): string
    {
        $auth = var_export(PRIVATE_PATH . '/auth_functions.php', true);
        $autoload = var_export(PROJECT_PATH . '/vendor/autoload.php', true);
        return <<<PHP
<?php
define('ARTIFACTS_DOMAIN', 'keeplore.app');
define('COOKIE_SECURE', true);
define('JWT_SECRET', 'test-jwt-secret-of-at-least-thirty-two-bytes');
require_once $autoload;
require_once $auth;
session_start();
\$mode = \$_GET['mode'] ?? '';
\$account = [
  'id' => 1,
  'name' => 'Sam Lee',
  'person_id' => null,
  'username' => 'ownerusername',
  'user_group' => 1,
];
if (\$mode === 'login') {
  \$previousSession = session_id();
  \$_SESSION['existing_field'] = 'kept';
  log_in_user(\$account, (\$_GET['remember'] ?? '') === '1');
  echo json_encode([
    'gc_lifetime' => (int) ini_get('session.gc_maxlifetime'),
    'cookie_lifetime' => session_get_cookie_params()['lifetime'],
    'regenerated' => session_id() !== \$previousSession,
    'existing_field' => \$_SESSION['existing_field'] ?? null,
    'user_id' => \$_SESSION['user_id'] ?? null,
  ]);
  return true;
}
if (\$mode === 'expire') {
  expire_access_token();
  echo 'expire';
  return true;
}
http_response_code(404);
echo 'missing';
return true;
PHP;
    }
}
