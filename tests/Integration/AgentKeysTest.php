<?php

namespace Tests\Integration;

use AgentKeys;
use PHPUnit\Framework\TestCase;

/**
 * Seam: AgentKeys, the owner's agent keys (ADR-0002). The owner issues,
 * lists and revokes their own keys; a token names its key's user only
 * while the key is active, and only the token's hash is stored.
 */
final class AgentKeysTest extends TestCase
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
        $this->db->query('CREATE TABLE agent_api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            agent_name VARCHAR(100) NOT NULL,
            key_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL DEFAULT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uq_agent_api_keys_hash (key_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        require_once PRIVATE_PATH . '/classes/AgentKeys.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function keys(int $userId = 1): AgentKeys
    {
        return new AgentKeys($this->db, $userId);
    }

    private function row(int $id): array
    {
        return $this->db->query("SELECT * FROM agent_api_keys WHERE id = $id")->fetch_assoc();
    }

    public function test_issuing_returns_a_bearer_shaped_token_whose_hash_not_the_token_is_stored(): void
    {
        $first = $this->keys()->issue('  weekly-review  ');
        $second = $this->keys()->issue('weekly-review');

        $this->assertMatchesRegularExpression('/\Aak_[A-Za-z0-9\-_]{40,}\z/', $first['token']);
        $this->assertNotSame($first['token'], $second['token']);
        $row = $this->row($first['id']);
        $this->assertSame('1', (string) $row['user_id']);
        $this->assertSame('weekly-review', $row['agent_name']);
        $this->assertSame(hash('sha256', $first['token']), $row['key_hash']);
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM agent_api_keys WHERE key_hash = '" . $this->db->real_escape_string($first['token']) . "'"
        )->fetch_row()[0]);
    }

    public function test_a_blank_or_too_long_name_is_refused_and_nothing_is_stored(): void
    {
        foreach (['', '   ', str_repeat('a', 101), str_repeat('é', 101)] as $name) {
            try {
                $this->keys()->issue($name);
                $this->fail('Issued a key named ' . var_export($name, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM agent_api_keys')->fetch_row()[0]);

        $this->assertSame(str_repeat('é', 100), $this->row($this->keys()->issue(str_repeat('é', 100))['id'])['agent_name']);
    }

    public function test_the_blank_name_message_is_the_settings_pages(): void
    {
        $this->expectExceptionMessage('Agent name cannot be blank.');
        $this->keys()->issue(' ');
    }

    public function test_all_is_the_owners_keys_newest_first(): void
    {
        $older = $this->keys()->issue('older')['id'];
        $this->keys(2)->issue('someone else');
        $newer = $this->keys()->issue('newer')['id'];
        $this->keys()->revoke($older);

        $keys = $this->keys()->all();

        $this->assertSame([$newer, $older], array_map(fn ($key) => $key['id'], $keys));
        $this->assertSame(['newer', 'older'], array_column($keys, 'agent_name'));
        $this->assertSame(['id', 'agent_name', 'created_at', 'last_used_at', 'revoked_at'], array_keys($keys[0]));
        $this->assertNull($keys[0]['revoked_at']);
        $this->assertNotNull($keys[1]['revoked_at']);
    }

    public function test_revoke_refuses_another_owners_key_and_an_already_revoked_key(): void
    {
        $theirs = $this->keys(2)->issue('theirs')['id'];
        $mine = $this->keys()->issue('mine')['id'];
        $this->keys()->revoke($mine);

        foreach ([$theirs, $mine, 999] as $id) {
            try {
                $this->keys()->revoke($id);
                $this->fail("Revoked key $id");
            } catch (\OutOfBoundsException) {
            }
        }
        $this->assertNull($this->row($theirs)['revoked_at']);
    }

    public function test_a_token_gives_its_keys_user_and_records_it_as_used(): void
    {
        $issued = $this->keys(2)->issue('reader');
        $this->assertNull($this->row($issued['id'])['last_used_at']);

        $this->assertSame(2, AgentKeys::userForToken($this->db, $issued['token']));
        $this->assertNotNull($this->row($issued['id'])['last_used_at']);
    }

    public function test_an_unknown_token_or_a_revoked_keys_token_gives_null(): void
    {
        $issued = $this->keys()->issue('reader');
        $this->keys()->revoke($issued['id']);

        $this->assertNull(AgentKeys::userForToken($this->db, $issued['token']));
        $this->assertNull(AgentKeys::userForToken($this->db, 'ak_unknown'));
        $this->assertNull(AgentKeys::userForToken($this->db, ''));
        $this->assertNull($this->row($issued['id'])['last_used_at']);
    }
}
