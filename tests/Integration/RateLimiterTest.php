<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seams: RateLimiter::isRateLimited() and RateLimiter::recordAttempt().
 * A check deletes expired rate_limits rows only for the endpoint passed in.
 * Counts and inserts use the connection peer address, not X-Forwarded-For.
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
        $this->db->query(
            "INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES
             ('198.51.100.20', 'login', NOW()),
             ('198.51.100.20', 'login', NOW()),
             ('198.51.100.20', 'login', NOW()),
             ('198.51.100.20', 'login', NOW()),
             ('198.51.100.20', 'login', NOW())"
        );

        $this->assertFalse($limiter->isRateLimited('login', 5, 900));

        $this->db->query(
            "INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES
             ('203.0.113.10', 'login', NOW()),
             ('203.0.113.10', 'login', NOW()),
             ('203.0.113.10', 'login', NOW()),
             ('203.0.113.10', 'login', NOW()),
             ('203.0.113.10', 'login', NOW())"
        );

        $this->assertTrue($limiter->isRateLimited('login', 5, 900));
    }

    private function recordedAddress(string $endpoint): string
    {
        $statement = $this->db->prepare(
            'SELECT ip_address FROM rate_limits WHERE endpoint = ?'
        );
        $statement->bind_param('s', $endpoint);
        $statement->execute();
        $address = $statement->get_result()->fetch_row()[0];
        $statement->close();

        return $address;
    }

    private function countFor(string $endpoint): string
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE endpoint = ?');
        $statement->bind_param('s', $endpoint);
        $statement->execute();
        $count = $statement->get_result()->fetch_row()[0];
        $statement->close();

        return $count;
    }
}
