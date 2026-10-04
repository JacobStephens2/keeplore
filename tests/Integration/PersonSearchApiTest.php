<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: search_people_over_api(), the person search behind POST /users.php
 * that Record Use, Edit Use and the Interact By popup use. It reads through
 * People for the signed-in owner, whatever userid the body sends. The
 * result is the status code and the response fields.
 */
final class PersonSearchApiTest extends TestCase
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
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        $this->runSql('ALTER TABLE players
            ADD COLUMN FullName VARCHAR(255) DEFAULT NULL,
            ADD COLUMN G VARCHAR(10) DEFAULT NULL,
            ADD COLUMN birth_year INT DEFAULT NULL,
            ADD COLUMN represents_user_id INT DEFAULT NULL');
        $this->runSql("INSERT INTO players (id, user_id, FirstName, LastName) VALUES (201, 2, 'Sam', 'Other')");
        require_once PRIVATE_PATH . '/people_api.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function runSql(string $sql): void
    {
        $this->db->multi_query($sql);
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    private function session(int $userId = 1): \ApiCaller
    {
        return \ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'session', 'user_id' => $userId]);
    }

    public function test_search_answers_the_owners_matches_with_the_published_fields(): void
    {
        [$status, $fields] = search_people_over_api($this->db, $this->session(), json_decode('{"query": "sam"}'));

        $this->assertSame(200, $status);
        $this->assertSame([
            'users' => [['id' => 100, 'FullName' => 'Sam Lee', 'FirstName' => 'Sam', 'LastName' => 'Lee']],
        ], $fields);
    }

    public function test_search_ignores_a_body_userid_for_another_owner(): void
    {
        [, $byQuery] = search_people_over_api($this->db, $this->session(), json_decode('{"query": "sam", "userid": 2}'));
        [, $blank] = search_people_over_api($this->db, $this->session(), json_decode('{"query": "", "userid": 2}'));

        $this->assertSame([100], array_column($byQuery['users'], 'id'));
        $this->assertSame([101, 100], array_column($blank['users'], 'id'));
    }

    public function test_a_missing_query_or_body_answers_the_owners_list(): void
    {
        $this->assertSame([101, 100], array_column(search_people_over_api($this->db, $this->session(), json_decode('{}'))[1]['users'], 'id'));
        $this->assertSame([101, 100], array_column(search_people_over_api($this->db, $this->session(), null)[1]['users'], 'id'));
    }

    public function test_search_refuses_the_master_key(): void
    {
        $masterKey = \ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'api_key']);

        [$status, $fields] = search_people_over_api($this->db, $masterKey, json_decode('{"query": "", "userid": 2}'));

        $this->assertSame(400, $status);
        $this->assertSame(['message' => 'users.php requires a user-scoped key.'], $fields);
    }

    public function test_search_refuses_an_agent_key(): void
    {
        $agentKey = \ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'agent_key', 'user_id' => 1]);

        [$status] = search_people_over_api($this->db, $agentKey, json_decode('{"query": "sam"}'));

        $this->assertSame(403, $status);
    }
}
