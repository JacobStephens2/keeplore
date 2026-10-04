<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Uses;

/**
 * Seams: list_uses_over_api(), record_use_over_api() and
 * delete_use_over_api(), the HTTP uses endpoint's GET, POST and DELETE.
 * All go through the Uses module for the owner: the session's user, or with
 * the master key the user_id the body or query names. The result is the
 * status code and the response fields.
 */
final class UseApiTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->db->query('CREATE TABLE uses_players (
            id INT PRIMARY KEY AUTO_INCREMENT,
            use_id INT NOT NULL,
            player_id INT NOT NULL,
            user_id INT NOT NULL,
            UNIQUE KEY use_player (use_id, player_id)
        ) ENGINE=InnoDB');
        require_once PRIVATE_PATH . '/use_api.php';
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
        return \ApiCaller::session($this->db, $userId);
    }

    private function agentKey(int $userId = 1): \ApiCaller
    {
        return \ApiCaller::agentKey($this->db, $userId);
    }

    private function masterKey(): \ApiCaller
    {
        return \ApiCaller::masterKey($this->db);
    }

    private function useCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM uses')->fetch_row()[0];
    }

    private function peopleLinks(int $useId): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM uses_players WHERE use_id = $useId")->fetch_row()[0];
    }

    public function test_get_lists_the_owners_uses_with_the_published_field_names(): void
    {
        [$id] = (new Uses($this->db, 1))->record([
            'item_id' => 10, 'use_date' => '2026-09-12', 'setting' => 'Cabin', 'notes' => 'Close', 'player_ids' => [101, 100],
        ]);
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12', 'player_ids' => [200]]);

        [$status, $fields] = list_uses_over_api($this->db, $this->agentKey(), ['player_id' => '101']);

        $this->assertSame(200, $status);
        $this->assertSame([[
            'id' => $id,
            'artifact_id' => 10,
            'artifact_title' => 'Catan',
            'use_date' => '2026-09-12',
            'note' => 'Cabin',
            'notesTwo' => 'Close',
            'players' => [101, 100],
            'participants' => [
                ['id' => 101, 'FirstName' => 'Jo', 'LastName' => 'Smith'],
                ['id' => 100, 'FirstName' => 'Sam', 'LastName' => 'Lee'],
            ],
        ]], $fields['uses']);
        $this->assertCount(2, list_uses_over_api($this->db, $this->session(), ['artifact_id' => '10'])[1]['uses']);
        $this->assertSame([], list_uses_over_api($this->db, $this->session(), ['player_id' => '200'])[1]['uses']);
    }

    public function test_master_key_get_by_item_reads_the_items_owners_uses(): void
    {
        [$theirs] = (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12']);

        [$status, $fields] = list_uses_over_api($this->db, $this->masterKey(), ['artifact_id' => '20']);

        $this->assertSame(200, $status);
        $this->assertSame([$theirs], array_column($fields['uses'], 'id'));
        $this->assertSame([], list_uses_over_api($this->db, $this->masterKey(), ['artifact_id' => '10', 'user_id' => '2'])[1]['uses']);
        $this->assertSame(400, list_uses_over_api($this->db, $this->masterKey(), [])[0]);
        $this->assertSame(400, list_uses_over_api($this->db, $this->masterKey(), ['user_id' => '9'])[0]);
    }

    public function test_post_records_one_use_and_replies_with_it(): void
    {
        [$status, $fields] = record_use_over_api($this->db, $this->session(), json_decode(
            '{"artifact_id": "10", "use_date": "2026-09-12", "note": "Cabin", "notesTwo": "Close", "count": 5, "user_id": 2}'
        ));

        $this->assertSame(201, $status);
        $this->assertSame('Use recorded successfully.', $fields['message']);
        $id = $fields['use']['id'];
        $this->assertSame([
            'id' => $id, 'artifact_id' => 10, 'use_date' => '2026-09-12', 'user_id' => 1, 'note' => 'Cabin', 'notesTwo' => 'Close',
        ], $fields['use']);
        $this->assertSame(2, $this->useCount());
        $this->assertSame('Cabin', (new Uses($this->db, 1))->find($id)['setting']);
    }

    public function test_master_key_post_records_for_the_named_owner(): void
    {
        [$status, $fields] = record_use_over_api($this->db, $this->masterKey(), json_decode(
            '{"artifact_id": 20, "use_date": "2026-09-12", "user_id": 2}'
        ));

        $this->assertSame(201, $status);
        $this->assertSame(2, $fields['use']['user_id']);
        $this->assertSame(20, (new Uses($this->db, 2))->find($fields['use']['id'])['item_id']);
    }

    public function test_post_of_another_owners_item_is_refused_with_the_modules_message(): void
    {
        foreach ([[$this->session(), 1], [$this->masterKey(), 1]] as [$caller, $owner]) {
            [$status, $fields] = record_use_over_api($this->db, $caller, json_decode(
                '{"artifact_id": 20, "use_date": "2026-09-12", "user_id": ' . $owner . '}'
            ));

            $this->assertSame(400, $status);
            $this->assertSame(['message' => 'Choose an item from your own items.'], $fields);
        }
        $this->assertSame(1, $this->useCount());
    }

    public function test_post_refusals_are_400s(): void
    {
        $refusals = [
            '{"use_date": "2026-09-12"}' => 'Please choose an item.',
            '{"artifact_id": 10, "use_date": "2026-02-30"}' => 'Enter a valid date in YYYY-MM-DD format.',
            '{"artifact_id": 10, "use_date": "2026-09-12", "note": ["x"]}' => 'Enter text for the Setting and notes.',
            'null' => 'Invalid or missing JSON request body.',
        ];
        foreach ($refusals as $body => $message) {
            $this->assertSame([400, ['message' => $message]], record_use_over_api($this->db, $this->session(), json_decode($body)));
        }
        $this->assertSame(400, record_use_over_api($this->db, $this->masterKey(), json_decode('{"artifact_id": 10, "use_date": "2026-09-12"}'))[0]);
        $this->assertSame(1, $this->useCount());
    }

    public function test_delete_removes_the_use_and_its_people_links(): void
    {
        [$id] = (new Uses($this->db, 1))->record(['item_id' => 10, 'use_date' => '2026-09-12', 'player_ids' => [100, 101]]);

        [$status, $fields] = delete_use_over_api($this->db, $this->session(), ['id' => (string) $id]);

        $this->assertSame(200, $status);
        $this->assertSame('Use record deleted successfully.', $fields['message']);
        $this->assertSame([100, 101], $fields['use']['players']);
        $this->assertNull((new Uses($this->db, 1))->find($id));
        $this->assertSame(0, $this->peopleLinks($id));
    }

    public function test_delete_of_another_owners_use_is_a_404(): void
    {
        [$theirs] = (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12', 'player_ids' => [200]]);

        foreach ([[$this->session(), []], [$this->masterKey(), ['user_id' => '1']]] as [$caller, $query]) {
            [$status, $fields] = delete_use_over_api($this->db, $caller, ['id' => (string) $theirs] + $query);

            $this->assertSame(404, $status);
            $this->assertSame(['message' => 'Use record not found.'], $fields);
        }
        $this->assertSame(400, delete_use_over_api($this->db, $this->masterKey(), ['id' => (string) $theirs])[0]);
        $this->assertNotNull((new Uses($this->db, 2))->find($theirs));
        $this->assertSame(1, $this->peopleLinks($theirs));

        $this->assertSame(200, delete_use_over_api($this->db, $this->masterKey(), ['id' => (string) $theirs, 'user_id' => '2'])[0]);
    }

    public function test_agent_key_writes_are_refused(): void
    {
        [$id] = (new Uses($this->db, 1))->record(['item_id' => 10, 'use_date' => '2026-09-12']);

        [$status, $fields] = record_use_over_api($this->db, $this->agentKey(), json_decode('{"artifact_id": 10, "use_date": "2026-09-12"}'));
        $this->assertSame(403, $status);
        $this->assertSame('Agent keys permit reads plus the kept toggle only.', $fields['message']);

        $this->assertSame(403, delete_use_over_api($this->db, $this->agentKey(), ['id' => (string) $id])[0]);
        $this->assertSame(2, $this->useCount());
    }
}
