<?php

namespace Tests\Integration;

use ApiCaller;
use PHPUnit\Framework\TestCase;

/**
 * Seam: ApiCaller, who an authenticated HTTP API request is and whose data
 * it may act for. A session and an agent key act for their own user; the
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
        require_once PRIVATE_PATH . '/classes/ApiCaller.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function caller(array $authentication): ApiCaller
    {
        return ApiCaller::from($this->db, (object) (['authenticated' => true] + $authentication));
    }

    public function test_an_authentication_that_did_not_authenticate_gives_no_caller(): void
    {
        $this->assertNull(ApiCaller::from($this->db, (object) ['message' => 'You have not been authenticated']));
        $this->assertNull(ApiCaller::from($this->db, (object) ['authenticated' => false, 'user_id' => 1]));
    }

    public function test_a_session_and_an_agent_key_act_for_their_own_user_whatever_is_requested(): void
    {
        foreach (['session', 'agent_key'] as $type) {
            $caller = $this->caller(['auth_type' => $type, 'user_id' => 1]);

            $this->assertSame(1, $caller->owner());
            $this->assertSame(1, $caller->owner(2));
            $this->assertSame(1, $caller->owner('9'));
            $this->assertSame(1, $caller->owner('nope'));
        }
    }

    public function test_the_master_key_acts_for_the_existing_user_it_names(): void
    {
        $master = $this->caller(['auth_type' => 'api_key']);

        $this->assertSame(2, $master->owner(2));
        $this->assertSame(2, $master->owner('2'));
    }

    public function test_the_master_key_acts_for_no_one_when_the_named_user_is_missing_malformed_or_unknown(): void
    {
        $master = $this->caller(['auth_type' => 'api_key']);

        foreach ([null, '', '0', '-1', '1.5', 'two', [2], '9'] as $requested) {
            $this->assertNull($master->owner($requested), var_export($requested, true));
        }
    }

    public function test_only_an_agent_key_is_refused(): void
    {
        $this->assertSame(
            [403, ['authenticated' => true, 'message' => 'Agent keys permit reads plus the kept toggle only.']],
            $this->caller(['auth_type' => 'agent_key', 'user_id' => 1])->agentKeyRefusal()
        );
        $this->assertNull($this->caller(['auth_type' => 'session', 'user_id' => 1])->agentKeyRefusal());
        $this->assertNull($this->caller(['auth_type' => 'api_key'])->agentKeyRefusal());
    }
}
