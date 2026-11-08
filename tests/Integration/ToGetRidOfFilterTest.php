<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ToGetRidOfFilterTest extends TestCase
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
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/local-schema.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/local-seed.sql'));
        $this->db->query('UPDATE games SET to_get_rid_of = 1 WHERE id IN (1, 2, 3)');
        $this->db->query("INSERT INTO users (id, first_name, last_name, email, username, hashed_password)
            VALUES (2, 'Other', 'Owner', 'other@keeplore.test', 'other', 'unused')");
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (4, 'private type', 2)");
        $this->db->query("INSERT INTO games (id, Title, user_id, type_id, to_get_rid_of) VALUES
            (4, 'Private item', 2, 4, 1), (5, 'Still keeping', 1, 1, 0),
            (6, 'Untyped item', 1, NULL, 1)");
        $this->servePage();
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
            foreach (['private', 'ui/artifacts', 'ui', 'sessions', 'cache', 'logs', ''] as $directory) {
                foreach (glob($this->root . '/' . $directory . '/*') as $path) {
                    if (is_link($path) || is_file($path)) {
                        unlink($path);
                    }
                }
                rmdir($this->root . ($directory === '' ? '' : '/' . $directory));
            }
        }
    }

    public function test_submitting_a_type_lists_only_flagged_items_of_that_type(): void
    {
        $html = $this->request(['csrf_token' => $this->csrf(), 'type' => ['1' => '1']]);

        $this->assertSame(['Azul', 'Catan'], $this->titles($html));
        $this->assertStringContainsString('id="rid-of-count">2</span>', $html);
        $this->assertSame(['1'], $this->checkedTypes($html));
    }

    public function test_all_types_initially_includes_untyped_items_and_only_offers_the_owners_types(): void
    {
        $html = $this->request();

        $this->assertSame(['Azul', 'Catan', 'The Left Hand of Darkness', 'Untyped item'], $this->titles($html));
        $this->assertSame(['1', '2', '3'], $this->checkedTypes($html));
        $this->assertStringNotContainsString('private type', $html);
    }

    public function test_the_type_selection_is_remembered_on_the_next_visit(): void
    {
        $this->request(['csrf_token' => $this->csrf(), 'type' => ['2' => '2']]);

        $html = $this->request();

        $this->assertSame(['The Left Hand of Darkness'], $this->titles($html));
        $this->assertSame(['2'], $this->checkedTypes($html));
    }

    public function test_an_empty_selection_can_be_recovered_by_selecting_all(): void
    {
        $token = $this->csrf();
        $html = $this->request(['csrf_token' => $token]);

        $this->assertSame([], $this->titles($html));
        $this->assertSame([], $this->checkedTypes($html));
        $this->assertStringContainsString('No items match these types', $html);
        $this->assertStringContainsString('Apply filters', $html);

        $html = $this->request(['csrf_token' => $token, 'type' => ['1', '2', '3']]);

        $this->assertSame(['Azul', 'Catan', 'The Left Hand of Darkness', 'Untyped item'], $this->titles($html));
    }

    public function test_types_without_flagged_items_show_a_filtered_empty_state(): void
    {
        $html = $this->request(['csrf_token' => $this->csrf(), 'type' => ['3']]);

        $this->assertSame([], $this->titles($html));
        $this->assertSame(['3'], $this->checkedTypes($html));
        $this->assertStringContainsString('No items match these types', $html);
        $this->assertStringNotContainsString('Nothing marked to get rid of', $html);
    }

    public function test_a_submitted_foreign_type_does_not_expose_its_items(): void
    {
        $html = $this->request(['csrf_token' => $this->csrf(), 'type' => ['4']]);

        $this->assertSame([], $this->titles($html));
        $this->assertSame([], $this->checkedTypes($html));
        $this->assertStringNotContainsString('Private item', $html);
    }

    public function test_guests_can_filter_without_receiving_item_actions(): void
    {
        $html = $this->request(['csrf_token' => $this->csrf(), 'type' => ['1']], '?guest=1');

        $this->assertSame(['Azul', 'Catan'], $this->titles($html));
        $this->assertStringNotContainsString('class="restore-form"', $html);
        $this->assertStringNotContainsString('class="kept-toggle-form"', $html);
    }

    private function csrf(): string
    {
        preg_match('/name="csrf_token" value="([^"]+)"/', $this->request(), $match);
        $this->assertNotEmpty($match[1] ?? null, 'The filter needs a CSRF token.');
        return $match[1];
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

    private function servePage(): void
    {
        $this->root = sys_get_temp_dir() . '/keeplore-rid-of-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        foreach (['private', 'ui', 'ui/artifacts', 'sessions'] as $directory) {
            mkdir($this->root . '/' . $directory);
        }
        // Use the real bootstrap and page with disposable database configuration.
        foreach (glob(PRIVATE_PATH . '/*') as $path) {
            if (basename($path) === 'initialize.php') {
                copy($path, $this->root . '/private/initialize.php');
            } elseif (basename($path) !== 'environment_variables.php') {
                symlink($path, $this->root . '/private/' . basename($path));
            }
        }
        if (!is_dir($this->root . '/private/vendor')) {
            symlink(PROJECT_PATH . '/vendor', $this->root . '/private/vendor');
        }
        copy(PROJECT_PATH . '/ui/artifacts/to-get-rid-of.php', $this->root . '/ui/artifacts/to-get-rid-of.php');
        foreach (['style.css', 'shared', 'assets'] as $path) {
            symlink(PROJECT_PATH . '/ui/' . $path, $this->root . '/ui/' . $path);
        }
        $configuration = [
            'DB_SERVER' => getenv('KEEPLORE_TEST_DB_HOST'),
            'DB_USER' => getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            'DB_PASS' => getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            'DB_NAME' => $this->databaseName,
            'DB_PORT' => (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306),
            'COOKIE_SECURE' => false,
            'ARTIFACTS_DOMAIN' => '',
            'API_ORIGIN' => '127.0.0.1',
            'JWT_SECRET' => 'test-only-secret',
            'KEEPLORE_ENV' => 'staging',
        ];
        $source = "<?php\n";
        foreach ($configuration as $name => $value) {
            $source .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        file_put_contents($this->root . '/private/environment_variables.php', $source);
        file_put_contents($this->root . '/router.php', <<<'PHP'
<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/artifacts/to-get-rid-of.php') {
    return false;
}
require __DIR__ . '/private/initialize.php';
put_account_in_session(['id' => 1, 'name' => 'Local Dev', 'person_id' => 1, 'username' => 'localdev', 'user_group' => 1]);
$_SESSION['guest_mode'] = isset($_GET['guest']);
chdir(__DIR__ . '/ui/artifacts');
require 'to-get-rid-of.php';
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions',
             '-S', '127.0.0.1:' . $this->port, '-t', $this->root . '/ui', $this->root . '/router.php'],
            [0 => ['file', '/dev/null', 'r'],
             1 => ['file', $this->root . '/server.log', 'a'],
             2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            $this->root
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
        $this->fail('To get rid of server did not start.');
    }

    private function request(?array $post = null, string $query = ''): string
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . '/artifacts/to-get-rid-of.php' . $query);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_COOKIEFILE => $this->root . '/cookies',
            CURLOPT_COOKIEJAR => $this->root . '/cookies',
        ]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $html = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $this->assertSame(200, $status, (string) $html . file_get_contents($this->root . '/server.log'));
        return (string) $html;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        return new \DOMXPath($dom);
    }

    private function titles(string $html): array
    {
        return array_map(fn ($node) => trim($node->textContent), iterator_to_array(
            $this->xpath($html)->query('//table[@id="rid-of-table"]//td[@class="name"]/a')
        ));
    }

    private function checkedTypes(string $html): array
    {
        return array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array(
            $this->xpath($html)->query('//span[@id="typeCheckboxes"]//input[@checked]')
        ));
    }
}
