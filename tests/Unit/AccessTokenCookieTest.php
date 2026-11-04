<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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
    private $server = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $router = '';
    private string $log = '';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'keeplore-cookie-log-');
        $router = tempnam(sys_get_temp_dir(), 'keeplore-cookie-');
        unlink($router);
        $this->router = $router . '.php';
        file_put_contents($this->router, $this->routerSource());
        $this->port = $this->freePort();
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->log, 'a'],
                2 => ['file', $this->log, 'a'],
            ],
            $this->pipes,
            PROJECT_PATH
        );
        $this->assertIsResource($this->server);
        $this->waitUntilServing();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ([$this->router, $this->log] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
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

    private function assertAccessTokenCookie(string $cookie, int $maxAge): void
    {
        $this->assertStringContainsString('SameSite=Strict', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('path=/', $cookie);
        $this->assertStringContainsString('domain=keeplore.app', $cookie);
        $this->assertStringContainsString('; secure;', $cookie);
        $this->assertStringContainsString('Max-Age=' . $maxAge . ';', $cookie);
    }

    /** @return array{headers: string, body: string} */
    private function request(string $mode): array
    {
        $handle = curl_init('http://127.0.0.1:' . $this->port . '/?mode=' . $mode);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $this->assertNotFalse($raw, $error . "\n" . $this->serverLog());
        $this->assertSame(200, $status, $raw . "\n" . $this->serverLog());
        [$headers, $body] = explode("\r\n\r\n", $raw, 2);
        return ['headers' => $headers, 'body' => $body];
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

    private function waitUntilServing(): void
    {
        $deadline = microtime(true) + 3;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->fail("Access token cookie server did not start.\n" . $this->serverLog());
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr($address, ':'), 1);
    }

    private function serverLog(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
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
  log_in_user(\$account, (\$_GET['remember'] ?? '') === '1');
  echo 'login';
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
