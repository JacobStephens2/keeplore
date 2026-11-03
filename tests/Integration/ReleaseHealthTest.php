<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ReleaseHealthTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private string $root = '';
    private $server = null;
    private int $port;

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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/local-schema.sql'));
        $this->serveApi();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
        if ($this->root !== '') {
            foreach (glob($this->root . '/private/*') as $path) {
                unlink($path);
            }
            foreach (glob($this->root . '/api/*') as $path) {
                unlink($path);
            }
            unlink($this->root . '/server.log');
            rmdir($this->root . '/private');
            rmdir($this->root . '/api');
            rmdir($this->root);
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

    private function serveApi(): void
    {
        $this->root = sys_get_temp_dir() . '/keeplore-health-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        mkdir($this->root . '/private');
        mkdir($this->root . '/api');
        // Run the real endpoint and bootstrap with disposable DB configuration.
        foreach (glob(PRIVATE_PATH . '/*') as $path) {
            if (basename($path) !== 'environment_variables.php') {
                symlink($path, $this->root . '/private/' . basename($path));
            }
        }
        if (!is_dir($this->root . '/private/vendor')) {
            symlink(PROJECT_PATH . '/vendor', $this->root . '/private/vendor');
        }
        foreach (glob(PROJECT_PATH . '/api/*') as $path) {
            if (basename($path) === 'index.php') {
                copy($path, $this->root . '/api/index.php');
            } else {
                symlink($path, $this->root . '/api/' . basename($path));
            }
        }
        $configuration = [
            'DB_SERVER' => getenv('KEEPLORE_TEST_DB_HOST'),
            'DB_USER' => getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            'DB_PASS' => getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            'DB_NAME' => $this->databaseName,
            'DB_PORT' => (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306),
        ];
        $source = "<?php\n";
        foreach ($configuration as $name => $value) {
            $source .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        file_put_contents($this->root . '/private/environment_variables.php', $source);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port],
            [0 => ['file', '/dev/null', 'r'],
             1 => ['file', $this->root . '/server.log', 'a'],
             2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            $this->root . '/api'
        );
        $this->assertIsResource($this->server);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(10000);
        }
        $this->fail('API server did not start.');
    }

    private function get(string $path): array
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, $body];
    }

    public function test_health_refuses_promotion_without_the_password_reset_migration(): void
    {
        $this->db->query('ALTER TABLE users DROP COLUMN password_changed_at');

        [$status, $body] = $this->get('/api/health');

        $this->assertSame(503, $status, $body);
        $this->assertSame([
            'healthy' => false,
            'message' => 'Required database schema is unavailable. Apply release migrations before promotion.',
        ], json_decode($body, true));

        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-password-changed-at.sql'));
        [$status, $body] = $this->get('/api/health');
        $this->assertSame(200, $status, $body);
        $this->assertSame(['healthy' => true], json_decode($body, true));
    }

    public function test_health_refuses_promotion_without_a_migrated_table(): void
    {
        $this->db->query('DROP TABLE item_tags');

        [$status, $body] = $this->get('/api/health');

        $this->assertSame(503, $status, $body);
    }

    public function test_health_refuses_an_older_version_of_a_migrated_table(): void
    {
        $this->db->query('ALTER TABLE bgg_poll_best_players DROP COLUMN open_only');

        [$status, $body] = $this->get('/api/health');

        $this->assertSame(503, $status, $body);
    }

    public function test_health_accepts_the_current_schema_on_both_health_paths(): void
    {
        foreach (['/api/health', '/health?source=deploy'] as $path) {
            [$status, $body] = $this->get($path);

            $this->assertSame(200, $status, $body);
            $this->assertSame(['healthy' => true], json_decode($body, true));
        }
    }

    public function test_discovery_is_still_available_with_an_incomplete_schema(): void
    {
        $this->db->query('ALTER TABLE users DROP COLUMN password_changed_at');

        [$status, $body] = $this->get('/');

        $this->assertSame(200, $status, $body);
        $this->assertSame('Hello from the Keeplore API.', json_decode($body, true)['message']);
        $this->assertArrayHasKey('GET /artifact.php?id=<id>', json_decode($body, true)['endpoints']);
    }
}
