<?php

namespace Tests\Integration;

use ApiCaller;
use PHPUnit\Framework\TestCase;

/**
 * Seam: set_kept_over_api(), the HTTP kept toggle's POST. It flips kept on
 * one of the owner's Items, for a session or an agent key (ADR-0002); the
 * master key has no Items of its own. The result is the status code and the
 * response fields.
 */
final class KeptToggleApiTest extends TestCase
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
        require_once PRIVATE_PATH . '/kept_api.php';
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

    private function session(int $userId = 1): ApiCaller
    {
        return ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'session', 'user_id' => $userId]);
    }

    private function agentKey(int $userId = 1): ApiCaller
    {
        return ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'agent_key', 'user_id' => $userId]);
    }

    private function masterKey(): ApiCaller
    {
        return ApiCaller::from($this->db, (object) ['authenticated' => true, 'auth_type' => 'api_key']);
    }

    private function isKept(int $id): int
    {
        return (int) $this->db->query("SELECT is_kept FROM games WHERE id = $id")->fetch_row()[0];
    }

    public function test_an_agent_key_flips_its_owners_item_and_reads_it_back(): void
    {
        [$status, $fields] = set_kept_over_api($this->db, $this->agentKey(), (object) ['id' => 10, 'is_kept' => 0]);

        $this->assertSame(200, $status);
        $this->assertSame([
            'ok' => true,
            'artifact_id' => 10,
            'artifact_name' => 'Catan',
            'message' => 'Catan is no longer kept.',
            'is_kept' => 0,
            'is_in_secondary_collection' => 0,
        ], $fields);
        $this->assertSame(0, $this->isKept(10));
    }

    public function test_a_session_keeps_an_item(): void
    {
        [$status, $fields] = set_kept_over_api($this->db, $this->session(), (object) ['id' => '12', 'is_kept' => '1']);

        $this->assertSame(200, $status);
        $this->assertSame('Arrival is now kept.', $fields['message']);
        $this->assertSame(1, $fields['is_kept']);
        $this->assertSame(1, $fields['is_in_secondary_collection']);
        $this->assertSame(1, $this->isKept(12));
    }

    public function test_the_master_key_is_refused(): void
    {
        [$status, $fields] = set_kept_over_api($this->db, $this->masterKey(), (object) ['id' => 10, 'is_kept' => 0]);

        $this->assertSame(403, $status);
        $this->assertSame('This endpoint requires a user-scoped credential.', $fields['message']);
        $this->assertSame(1, $this->isKept(10));
    }

    public function test_another_accounts_item_is_not_found(): void
    {
        [$status, $fields] = set_kept_over_api($this->db, $this->agentKey(), (object) ['id' => 20, 'is_kept' => 0]);

        $this->assertSame(404, $status);
        $this->assertSame('Item not found.', $fields['message']);
        $this->assertSame(1, $this->isKept(20));
    }

    public static function missingIds(): array
    {
        return [
            'no body' => [null],
            'a JSON list' => [[10]],
            'no id' => [(object) ['is_kept' => 1]],
            'a word for an id' => [(object) ['id' => 'ten', 'is_kept' => 1]],
        ];
    }

    /** @dataProvider missingIds */
    public function test_a_missing_or_invalid_id_is_refused($body): void
    {
        [$status, $fields] = set_kept_over_api($this->db, $this->session(), $body);

        $this->assertSame(400, $status);
        $this->assertSame('Missing or invalid required field: id', $fields['message']);
    }

    public static function notZeroOrOne(): array
    {
        return [
            'missing' => [null],
            'two' => [2],
            'a word' => ['yes'],
            'a decimal' => [1.0],
            'a padded string' => [' 1'],
        ];
    }

    /** @dataProvider notZeroOrOne */
    public function test_is_kept_must_be_exactly_zero_or_one($isKept): void
    {
        $body = (object) ['id' => 10];
        if ($isKept !== null) {
            $body->is_kept = $isKept;
        }

        [$status, $fields] = set_kept_over_api($this->db, $this->session(), $body);

        $this->assertSame(400, $status);
        $this->assertSame('Missing or invalid required field: is_kept (0 or 1)', $fields['message']);
        $this->assertSame(1, $this->isKept(10));
    }

    public function test_true_and_false_are_one_and_zero(): void
    {
        set_kept_over_api($this->db, $this->session(), (object) ['id' => 10, 'is_kept' => false]);
        $this->assertSame(0, $this->isKept(10));

        set_kept_over_api($this->db, $this->session(), (object) ['id' => 10, 'is_kept' => true]);
        $this->assertSame(1, $this->isKept(10));
    }
}
