<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: start_guest_session(). When an Account starts browsing as guest,
 * the browser's next request no longer carries that Account's access token.
 * The Set-Cookie that drops it is the same one logging out sends. The
 * session that remains is a guest session, not the Account's.
 *
 * PHP's CLI SAPI does not record setcookie() in headers_list(), so this
 * watches a real HTTP response and the cookie jar a client keeps.
 */
class GuestSessionTest extends TestCase
{
    private $server = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $router = '';
    private string $log = '';
    private int $port = 0;
    /** @var list<string> */
    private array $jars = [];

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'keeplore-guest-log-');
        $router = tempnam(sys_get_temp_dir(), 'keeplore-guest-');
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
        foreach (array_merge([$this->router, $this->log], $this->jars) as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_browsing_as_guest_expires_the_accounts_access_token(): void
    {
        $guestJar = $this->cookieJar();
        $this->assertSame('account-token', $this->request('seen', $guestJar)['body']);

        $guest = $this->request('guest', $guestJar);
        $guestBody = json_decode($guest['body'], true);
        $this->assertSame(
            ['guest' => true, 'user_id' => 99, 'logged_in' => false, 'username' => 'Guest'],
            $guestBody
        );

        $logoutJar = $this->cookieJar();
        $logout = $this->request('logout', $logoutJar);

        $this->assertSame(
            $this->accessTokenCookie($logout['headers']),
            $this->accessTokenCookie($guest['headers'])
        );
        $this->assertNotNull($this->accessTokenCookie($guest['headers']));
        $this->assertSame('', $this->request('seen', $guestJar)['body']);
        $this->assertSame('', $this->request('seen', $logoutJar)['body']);
    }

    /** A client cookie jar that still holds the Account's access token. */
    private function cookieJar(): string
    {
        $jar = tempnam(sys_get_temp_dir(), 'keeplore-cookies-');
        $this->jars[] = $jar;
        $line = implode("\t", [
            '127.0.0.1',
            'FALSE',
            '/',
            'FALSE',
            '2000000000',
            'access_token',
            'account-token',
        ]);
        file_put_contents($jar, "# Netscape HTTP Cookie File\n" . $line . "\n");
        return $jar;
    }

    /** @return array{headers: string, body: string} */
    private function request(string $mode, string $jar): array
    {
        $handle = curl_init('http://127.0.0.1:' . $this->port . '/?mode=' . $mode);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_COOKIEJAR => $jar,
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
        $this->fail("Guest session server did not start.\n" . $this->serverLog());
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
        return <<<PHP
<?php
define('ARTIFACTS_DOMAIN', '');
define('COOKIE_SECURE', false);
define('DEMO_USER_ID', 99);
require_once $auth;
session_start();
\$mode = \$_GET['mode'] ?? '';
if (\$mode === 'logout') {
  \$_SESSION['user_id'] = 1;
  log_out();
  echo 'logout';
  return true;
}
if (\$mode === 'guest') {
  \$_SESSION = [
    'user_id' => 1,
    'logged_in' => true,
    'username' => 'ownerusername',
    'user_group' => 1,
  ];
  start_guest_session();
  echo json_encode([
    'guest' => is_guest(),
    'user_id' => \$_SESSION['user_id'] ?? null,
    'logged_in' => \$_SESSION['logged_in'] ?? null,
    'username' => \$_SESSION['username'] ?? null,
  ]);
  return true;
}
if (\$mode === 'seen') {
  echo \$_COOKIE['access_token'] ?? '';
  return true;
}
http_response_code(404);
echo 'missing';
return true;
PHP;
    }
}
