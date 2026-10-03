<?php

namespace Tests\Integration;

use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seams: write_item_over_api() and delete_item_over_api(), the HTTP API
 * item endpoint's POST, PUT and DELETE. All write through the Items module
 * for the owner: the session's user, or with the master key the user_id
 * the body (or for DELETE the query) names. The result is the status code
 * and the response fields.
 */
final class ItemApiWriteTest extends TestCase
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
        $this->runSql('DROP TABLE games, types');
        $this->runSql($this->schemaTable('types') . $this->schemaTable('games'));
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, type, is_kept, MnT, MxT, Acq) VALUES
            (10, 1, 'Catan', 1, 'board-game', 1, 60, 120, '2020-01-01'),
            (20, 2, 'Private item', 3, 'card game', 1, 30, 45, '2026-01-01')");
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'family'), (2, 20, 'mine')");
        require_once PRIVATE_PATH . '/item_api.php';
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

    private function schemaTable(string $table): string
    {
        $schema = file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=[^;]*;/s', $schema, $match);
        return $match[0];
    }

    private function session(int $userId = 1): object
    {
        return (object) ['authenticated' => true, 'auth_type' => 'session', 'user_id' => $userId];
    }

    private function masterKey(): object
    {
        return (object) ['authenticated' => true, 'auth_type' => 'api_key'];
    }

    private function body(string $json): mixed
    {
        return json_decode($json);
    }

    private function item(int $id, int $owner = 1): ?array
    {
        return (new Items($this->db, $owner))->find($id);
    }

    private function itemCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM games')->fetch_row()[0];
    }

    private function tagsOf(int $itemId): array
    {
        return array_column(
            $this->db->query("SELECT tag FROM item_tags WHERE artifact_id = $itemId ORDER BY tag")->fetch_all(MYSQLI_NUM),
            0
        );
    }

    public function test_post_creates_the_item_for_the_session_user_and_returns_it_with_its_tags(): void
    {
        [$status, $response] = write_item_over_api($this->db, $this->session(), 'POST', $this->body(
            '{"Title": "Quelf", "type_id": 2, "Notes": "Party game", "interaction_frequency_days": 14,
              "bgg_url": "boardgamegeek.com/boardgame/19370/quelf", "tags": ["Party", "family"], "user_id": 2}'
        ));

        $this->assertSame(201, $status);
        $artifact = $response['artifact'];
        $this->assertSame('Quelf', $artifact['Title']);
        $this->assertSame(1, (int) $artifact['user_id']);
        $this->assertSame(2, (int) $artifact['type_id']);
        $this->assertSame('film', $artifact['type']);
        $this->assertSame('film', $artifact['type_name']);
        $this->assertSame('Party game', $artifact['Notes']);
        $this->assertEquals(14, $artifact['interaction_frequency_days']);
        $this->assertSame('https://boardgamegeek.com/boardgame/19370/quelf', $artifact['bgg_url']);
        $this->assertSame(30, (int) $artifact['MnT']);
        $this->assertSame(['family', 'party'], $artifact['tags']);
        $this->assertSame($artifact, $this->item((int) $artifact['id']) + ['tags' => ['family', 'party']]);
    }

    public function test_post_writes_json_booleans_as_flags(): void
    {
        [, $response] = write_item_over_api($this->db, $this->session(), 'POST', $this->body(
            '{"Title": "Quelf", "is_kept": false, "is_digital": true, "is_physical": false}'
        ));

        $item = $this->item((int) $response['artifact']['id']);
        $this->assertSame([0, 1, 0], [(int) $item['is_kept'], (int) $item['is_digital'], (int) $item['is_physical']]);
    }

    public function test_post_ignores_the_legacy_string_type(): void
    {
        [, $response] = write_item_over_api($this->db, $this->session(), 'POST', $this->body(
            '{"Title": "Quelf", "type": "film"}'
        ));

        $this->assertNull($response['artifact']['type']);
        $this->assertNull($response['artifact']['type_id']);
    }

    public function test_an_invalid_post_returns_422_with_every_error_and_writes_nothing(): void
    {
        $before = $this->itemCount();

        [$status, $response] = write_item_over_api($this->db, $this->session(), 'POST', $this->body(
            '{"Title": "Q", "type_id": 3, "tags": "party"}'
        ));

        $this->assertSame(422, $status);
        $this->assertSame(['Title must be between 2 and 255 characters.', 'Type must be one of your types.'], $response['errors']);
        $this->assertSame($before, $this->itemCount());
    }

    public function test_a_post_without_a_title_returns_422(): void
    {
        foreach (['{"Notes": "x"}', '{}'] as $json) {
            [$status, $response] = write_item_over_api($this->db, $this->session(), 'POST', $this->body($json));
            $this->assertSame(422, $status, $json);
            $this->assertSame(['Title cannot be blank.'], $response['errors']);
        }
    }

    public function test_a_missing_or_non_object_body_returns_400(): void
    {
        foreach ([null, 'Quelf', [], [1, 2]] as $body) {
            [$status] = write_item_over_api($this->db, $this->session(), 'POST', $body);
            $this->assertSame(400, $status, var_export($body, true));
        }
    }

    public function test_post_with_the_master_key_creates_for_the_named_owner(): void
    {
        [$status, $response] = write_item_over_api($this->db, $this->masterKey(), 'POST', $this->body(
            '{"Title": "Gin rummy", "user_id": 2, "type_id": 3}'
        ));

        $this->assertSame(201, $status);
        $this->assertSame(2, (int) $response['artifact']['user_id']);
        $this->assertSame('card game', $response['artifact']['type']);
    }

    public function test_the_master_key_without_a_valid_owner_returns_400_and_writes_nothing(): void
    {
        $before = $this->itemCount();

        foreach (['{"Title": "Quelf"}', '{"Title": "Quelf", "user_id": 0}', '{"Title": "Quelf", "user_id": "me"}', '{"Title": "Quelf", "user_id": 999}'] as $json) {
            [$status, $response] = write_item_over_api($this->db, $this->masterKey(), 'POST', $this->body($json));
            $this->assertSame(400, $status, $json);
            $this->assertSame('Missing or invalid required field: user_id', $response['message']);
        }
        [$status] = write_item_over_api($this->db, $this->masterKey(), 'PUT', $this->body('{"id": 20, "Title": "Mine now"}'));
        $this->assertSame(400, $status);

        $this->assertSame($before, $this->itemCount());
        $this->assertSame('Private item', $this->item(20, 2)['Title']);
    }

    public function test_agent_keys_are_refused_and_write_nothing(): void
    {
        $agent = (object) ['authenticated' => true, 'auth_type' => 'agent_key', 'user_id' => 1];
        $before = $this->itemCount();

        [$status, $response] = write_item_over_api($this->db, $agent, 'POST', $this->body('{"Title": "Quelf"}'));
        $this->assertSame(403, $status);
        $this->assertSame('Agent keys permit reads plus the kept toggle only.', $response['message']);

        [$status] = write_item_over_api($this->db, $agent, 'PUT', $this->body('{"id": 10, "Title": "Catan Junior"}'));
        $this->assertSame(403, $status);

        $this->assertSame($before, $this->itemCount());
        $this->assertSame('Catan', $this->item(10)['Title']);
    }

    public function test_put_patches_only_the_fields_sent_and_returns_the_item_with_its_tags(): void
    {
        $before = $this->item(10);

        [$status, $response] = write_item_over_api($this->db, $this->session(), 'PUT', $this->body(
            '{"id": 10, "Notes": "Seafarers expansion", "type_id": 2}'
        ));

        $this->assertSame(200, $status);
        $after = $this->item(10);
        $this->assertSame('Seafarers expansion', $after['Notes']);
        $this->assertSame('film', $after['type']);
        $this->assertSame($after + ['tags' => ['family']], $response['artifact']);
        unset($before['Notes'], $before['type_id'], $before['type'], $before['type_name']);
        unset($after['Notes'], $after['type_id'], $after['type'], $after['type_name']);
        $this->assertSame($before, $after);
        $this->assertSame(['family'], $this->tagsOf(10));
    }

    public function test_put_replaces_the_tags_when_sent(): void
    {
        [, $response] = write_item_over_api($this->db, $this->session(), 'PUT', $this->body('{"id": 10, "tags": "Beach-safe"}'));

        $this->assertSame(['beach-safe'], $response['artifact']['tags']);
        $this->assertSame(['beach-safe'], $this->tagsOf(10));
    }

    public function test_put_for_an_item_the_owner_does_not_have_returns_404(): void
    {
        foreach ([20, 999] as $id) {
            [$status, $response] = write_item_over_api($this->db, $this->session(), 'PUT', (object) ['id' => $id, 'Title' => 'Mine now']);
            $this->assertSame(404, $status);
            $this->assertSame('Item not found.', $response['message']);
        }
        $this->assertSame('Private item', $this->item(20, 2)['Title']);
    }

    public function test_an_invalid_put_returns_422_and_leaves_the_item(): void
    {
        [$status, $response] = write_item_over_api($this->db, $this->session(), 'PUT', $this->body(
            '{"id": 10, "MnT": 500, "tags": "new"}'
        ));

        $this->assertSame(422, $status);
        $this->assertSame(['Minimum Time cannot exceed Maximum Time.'], $response['errors']);
        $this->assertSame(60, (int) $this->item(10)['MnT']);
        $this->assertSame(['family'], $this->tagsOf(10));
    }

    public function test_put_without_a_valid_id_returns_400(): void
    {
        foreach (['{"Title": "Catan"}', '{"id": "ten"}'] as $json) {
            [$status] = write_item_over_api($this->db, $this->session(), 'PUT', $this->body($json));
            $this->assertSame(400, $status, $json);
        }
    }

    public function test_put_with_the_master_key_patches_only_the_named_owners_item(): void
    {
        [$status] = write_item_over_api($this->db, $this->masterKey(), 'PUT', $this->body('{"id": 20, "user_id": 1, "Title": "Mine now"}'));
        $this->assertSame(404, $status);

        [$status, $response] = write_item_over_api($this->db, $this->masterKey(), 'PUT', $this->body('{"id": 20, "user_id": 2, "Notes": "Shuffled"}'));
        $this->assertSame(200, $status);
        $this->assertSame('Shuffled', $response['artifact']['Notes']);
        $this->assertSame(['mine'], $response['artifact']['tags']);
    }

    public function test_delete_removes_the_session_users_item_and_its_tags(): void
    {
        [$status, $response] = delete_item_over_api($this->db, $this->session(), ['id' => '10']);

        $this->assertSame(200, $status);
        $this->assertSame('Item deleted successfully.', $response['message']);
        $this->assertSame('Catan', $response['artifact']['Title']);
        $this->assertNull($this->item(10));
        $this->assertSame([], $this->tagsOf(10));
    }

    public function test_delete_of_an_item_the_owner_does_not_have_returns_404_and_leaves_it(): void
    {
        [$status, $response] = delete_item_over_api($this->db, $this->session(), ['id' => '20']);

        $this->assertSame(404, $status);
        $this->assertSame('Item not found.', $response['message']);
        $this->assertSame('Private item', $this->item(20, 2)['Title']);
        $this->assertSame(['mine'], $this->tagsOf(20));
    }

    public function test_delete_without_a_valid_id_returns_400(): void
    {
        foreach ([[], ['id' => 'ten'], ['id' => '0']] as $query) {
            [$status, $response] = delete_item_over_api($this->db, $this->session(), $query);
            $this->assertSame(400, $status);
            $this->assertSame('Missing or invalid required parameter: id', $response['message']);
        }
    }

    public function test_delete_with_the_master_key_needs_the_owner_in_the_query(): void
    {
        foreach ([['id' => '20'], ['id' => '20', 'user_id' => 'me'], ['id' => '20', 'user_id' => '999']] as $query) {
            [$status, $response] = delete_item_over_api($this->db, $this->masterKey(), $query);
            $this->assertSame(400, $status);
            $this->assertSame('Missing or invalid required parameter: user_id', $response['message']);
        }
        [$status] = delete_item_over_api($this->db, $this->masterKey(), ['id' => '20', 'user_id' => '1']);
        $this->assertSame(404, $status);
        $this->assertSame('Private item', $this->item(20, 2)['Title']);

        [$status] = delete_item_over_api($this->db, $this->masterKey(), ['id' => '20', 'user_id' => '2']);
        $this->assertSame(200, $status);
        $this->assertNull($this->item(20, 2));
    }

    public function test_delete_with_an_agent_key_is_refused_and_leaves_the_item(): void
    {
        $agent = (object) ['authenticated' => true, 'auth_type' => 'agent_key', 'user_id' => 1];

        [$status, $response] = delete_item_over_api($this->db, $agent, ['id' => '10']);

        $this->assertSame(403, $status);
        $this->assertSame('Agent keys permit reads plus the kept toggle only.', $response['message']);
        $this->assertSame('Catan', $this->item(10)['Title']);
    }
}
