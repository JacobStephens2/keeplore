<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seams: RateLimiter::isRateLimited() and RateLimiter::recordAttempt().
 * A check deletes expired rate_limits rows only for the endpoint passed in.
 * Counts and inserts key the connection peer, not X-Forwarded-For: an IPv6
 * peer on its /64 network, an IPv4-mapped peer as its IPv4 address.
 */
final class RateLimiterTest extends TestCase
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
        require_once PRIVATE_PATH . '/rate_limiter.php';
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_a_check_for_one_endpoint_does_not_remove_another_endpoints_rows(): void
    {
        $limiter = new \RateLimiter($this->db);
        $this->db->query(
            "INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES
             ('203.0.113.5', 'login', DATE_SUB(NOW(), INTERVAL 120 SECOND)),
             ('203.0.113.5', 'api', DATE_SUB(NOW(), INTERVAL 120 SECOND)),
             ('203.0.113.5', 'api', NOW())"
        );

        $limiter->isRateLimited('api', 60, 60);

        $this->assertSame('1', $this->countFor('login'));
        $this->assertSame('1', $this->countFor('api'));
    }

    public function test_a_recorded_attempt_stores_the_connection_peer_address_not_x_forwarded_for(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 203.0.113.10';

        $limiter = new \RateLimiter($this->db);
        $limiter->recordAttempt('login');

        $this->assertSame('203.0.113.10', $this->recordedAddress('login'));
    }

    public function test_a_check_counts_the_connection_peer_address_not_x_forwarded_for(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $limiter = new \RateLimiter($this->db);
        $this->insertAttempts('198.51.100.20', 'login', 5);

        $this->assertFalse($limiter->isRateLimited('login', 5, 900));

        $this->insertAttempts('203.0.113.10', 'login', 5);

        $this->assertTrue($limiter->isRateLimited('login', 5, 900));
    }

    public function test_two_addresses_in_one_ipv6_64_share_a_count(): void
    {
        $limiter = new \RateLimiter($this->db);
        $this->recordLoginAttemptsFrom($limiter, '2001:db8:a:b::1', 5);

        $this->assertTrue($this->loginIsRateLimitedFor($limiter, '2001:db8:a:b:ffff:ffff:ffff:ffff'));
    }

    public function test_addresses_in_different_ipv6_64s_do_not_share_a_count(): void
    {
        $limiter = new \RateLimiter($this->db);
        $this->recordLoginAttemptsFrom($limiter, '2001:db8:a:a:ffff:ffff:ffff:ffff', 5);

        $this->assertFalse($this->loginIsRateLimitedFor($limiter, '2001:db8:a:b::1'));
    }

    public function test_an_ipv4_mapped_peer_shares_its_ipv4_addresss_count(): void
    {
        $limiter = new \RateLimiter($this->db);
        $this->recordLoginAttemptsFrom($limiter, '203.0.113.7', 3);
        $this->recordLoginAttemptsFrom($limiter, '::ffff:203.0.113.7', 2);

        $this->assertTrue($this->loginIsRateLimitedFor($limiter, '::ffff:203.0.113.7'));
        $this->assertTrue($this->loginIsRateLimitedFor($limiter, '203.0.113.7'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('peerKeyProvider')]
    public function test_a_recorded_attempt_stores_the_peers_key(string $peer, string $key): void
    {
        $this->recordLoginAttemptsFrom(new \RateLimiter($this->db), $peer, 1);

        $this->assertSame($key, $this->recordedAddress('login'));
    }

    public static function peerKeyProvider(): array
    {
        return [
            'an IPv4 peer keeps its address' => ['203.0.113.10', '203.0.113.10'],
            'an IPv6 peer is its /64 in CIDR form' => ['2001:db8:a:b:1:2:3:4', '2001:db8:a:b::/64'],
            'an IPv4-mapped peer is its IPv4 address' => ['::ffff:203.0.113.7', '203.0.113.7'],
        ];
    }

    private function recordLoginAttemptsFrom(\RateLimiter $limiter, string $peer, int $count): void
    {
        $_SERVER['REMOTE_ADDR'] = $peer;
        for ($i = 0; $i < $count; $i++) {
            $limiter->recordAttempt('login');
        }
    }

    private function loginIsRateLimitedFor(\RateLimiter $limiter, string $peer): bool
    {
        $_SERVER['REMOTE_ADDR'] = $peer;

        return $limiter->isRateLimited('login', 5, 900);
    }

    private function insertAttempts(string $address, string $endpoint, int $count): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES (?, ?, NOW())'
        );
        $statement->bind_param('ss', $address, $endpoint);
        for ($i = 0; $i < $count; $i++) {
            $statement->execute();
        }
        $statement->close();
    }

    private function recordedAddress(string $endpoint): string
    {
        return $this->scalarFor($endpoint, 'SELECT ip_address FROM rate_limits WHERE endpoint = ?');
    }

    private function countFor(string $endpoint): string
    {
        return $this->scalarFor($endpoint, 'SELECT COUNT(*) FROM rate_limits WHERE endpoint = ?');
    }

    private function scalarFor(string $endpoint, string $sql): string
    {
        $statement = $this->db->prepare($sql);
        $statement->bind_param('s', $endpoint);
        $statement->execute();
        $value = $statement->get_result()->fetch_row()[0];
        $statement->close();

        return $value;
    }
}
