<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use ItemInteractionsPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalPhpServer;

require_once __DIR__ . '/MySqlTestCase.php';
require_once __DIR__ . '/../Support/LocalPhpServer.php';
require_once PRIVATE_PATH . '/item_interactions.php';

final class ItemInteractionsPageTest extends MySqlTestCase
{
    private const ROUTE = '/uses/interactions.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(<<<'SQL'
            ALTER TABLE types ADD COLUMN user_id INT NOT NULL DEFAULT 1;
            INSERT INTO types (id, objectType, user_id) VALUES (3, 'book', 2);
            UPDATE users SET default_use_interval = 6.50 WHERE id = 1;
            UPDATE games SET Title = 'Catan & <Sea>', Candidate = '03: Ann, Ben at home', ss = '03,04 & <5>' WHERE id = 10;
            UPDATE games SET type_id = 3, Title = 'A foreign spot',
                Candidate = '03: ann dee, ben cole,  at HOME (catan & <sea>' WHERE id = 20;
            UPDATE games SET Title = 'Zulu spot', type_id = 2,
                Candidate = '03: Ann Dee, Ben Cole,  at home (Catan & <Sea> later)' WHERE id = 13;
            INSERT INTO games (id, user_id, Title, type_id, is_kept, Candidate) VALUES
                (29, 1, 'Aardvark', 2, 0, 'Note: 03: Ann Dee, Ben Cole,  at home (Catan & <Sea>'),
                (30, 1, 'Alpha & <Spot>', 2, 0, '03: ann dee, ben cole,  AT HOME (catan & <sea> after dinner');
            INSERT INTO players VALUES (2, 1, 'Ann', 'Dee'), (10, 1, 'Ben', 'Cole');
            DELETE FROM uses;
            INSERT INTO uses (id, artifact_id, user_id, use_date, note) VALUES
                (39, 10, 1, '2026-05-20', 'home'), (31, 10, 1, '2026-05-19', 'home'),
                (35, 10, 1, '2026-05-20', 'home'), (36, 11, 1, '2026-05-20', 'home'),
                (42, 10, 1, '2026-05-21', 'online'), (43, 10, 1, '2026-05-22', 'Online'),
                (44, 10, 1, '2026-05-23', 'Home'), (45, 10, 1, '2026-05-18', 'Library & <Desk>'),
                (46, 12, 1, '2026-05-24', 'cinema'), (47, 20, 2, '2026-05-25', 'private'),
                (48, 10, 2, '2026-05-26', 'foreign Use of an owner Item');

            -- Legacy recordings can contain repeated links to the same Person.
            CREATE TABLE uses_players (
                id INT PRIMARY KEY AUTO_INCREMENT, use_id INT, player_id INT, user_id INT
            );
            INSERT INTO uses_players (use_id, player_id, user_id) VALUES
                (39, 10, 1), (39, 2, 1), (39, 2, 1), (39, 200, 2),
                (31, 10, 1), (31, 2, 1), (31, 2, 1),
                (35, 10, 1), (35, 2, 1), (35, 2, 1),
                (36, 10, 1), (36, 2, 1), (36, 2, 1),
                (42, 10, 1), (42, 2, 1), (43, 10, 1), (43, 2, 1),
                (44, 10, 1), (44, 2, 1), (44, 2, 1);
            SQL);
    }

    public function test_get_respects_remembered_types_and_falls_back_without_changing_the_session(): void
    {
        $session = ['type' => ['book' => 3, 'film' => 2], 'unrelated' => ['keep me']];
        $before = $session;
        $view = $this->page()->read('GET', [], $session);
        $this->assertSame(['types' => ['board-game' => 1, 'film' => 2], 'selected' => ['2']], $view['type_filter']);
        $this->assertSame([46], array_column($view['table']['rows'], 'id'));
        $this->assertSame($before, $session);

        foreach ([['3'], [], '1'] as $remembered) {
            $session['type'] = $remembered;
            $view = $this->page()->read('GET', [], $session);
            $this->assertSame(['1', '2'], $view['type_filter']['selected']);
            $this->assertSame([46, 44, 43, 42, 39, 36, 35, 31, 45], array_column($view['table']['rows'], 'id'));
            $this->assertSame(['type' => $remembered, 'unrelated' => ['keep me']], $session);
        }
    }

    public function test_post_remembers_owned_types_and_the_date_cutoff_is_inclusive(): void
    {
        $session = ['type' => ['2'], 'unrelated' => ['keep me'], 'user_id' => 2];
        $view = $this->page()->read('POST', ['type' => ['3', '1'], 'minimumDate' => '2026-05-20'], $session);

        $this->assertSame(['type' => ['1'], 'unrelated' => ['keep me'], 'user_id' => 2], $session);
        $this->assertSame(['interval' => '6.5', 'cutoff' => '05/25/2026'], $view['date_hint']);
        $this->assertSame('2026-05-20', $view['filters']['minimumDate']);
        $this->assertNull($view['error']);
        $this->assertSame([44, 43, 42, 39, 36, 35], array_column($view['table']['rows'], 'id'));
    }

    public function test_invalid_minimum_date_only_errors_when_types_are_selected(): void
    {
        $this->db->query('DROP TABLE item_tags');
        $session = ['type' => ['2'], 'unrelated' => true];
        $post = ['minimumDate' => '2026-02-30', 'showAttributes' => 'yes'];
        $invalid = $this->page()->read('POST', $post + ['type' => ['1']], $session);
        $this->assertSame(['type' => ['1'], 'unrelated' => true], $session);
        $this->assertSame('2026-02-30', $invalid['filters']['minimumDate']);
        $this->assertSame(['interval' => '6.5', 'cutoff' => '05/25/2026'], $invalid['date_hint']);
        $this->assertSame('Enter a valid date in YYYY-MM-DD format.', $invalid['error']);
        $this->assertNull($invalid['table']);

        $empty = $this->page()->read('POST', $post, $session);
        $this->assertSame(['type' => [], 'unrelated' => true], $session);
        $this->assertNull($empty['error']);
        $this->assertSame([], $empty['table']['rows']);
        $this->assertSame(0, $empty['table']['use_count']);
        $this->assertSame(0, $empty['table']['group_and_setting_count']);
        $this->assertSame(0, $empty['table']['group_setting_item_count']);
    }

    public function test_the_type_selection_is_remembered_even_when_use_storage_fails(): void
    {
        $this->db->query('DROP TABLE uses');
        $session = ['type' => ['2'], 'unrelated' => true];
        try {
            $this->page()->read('POST', ['type' => ['1']], $session);
            $this->fail('Expected the storage failure to propagate.');
        } catch (\mysqli_sql_exception $error) {
            $this->assertStringContainsString('uses', $error->getMessage());
            $this->assertSame(['type' => ['1'], 'unrelated' => true], $session);
        }
    }

    public static function hideOptions(): array
    {
        return [
            'neither' => ['no', 'no', [44, 43, 42, 39, 36, 35, 31], 4, 5, 7],
            'online only' => ['yes', 'no', [44, 43, 39, 36, 35, 31], 3, 4, 7],
            'duplicates only' => ['no', 'yes', [44, 43, 42, 39, 36], 4, 5, null],
            'both' => ['yes', 'yes', [44, 43, 39, 36], 3, 4, null],
            'nonmatching values' => ['YES', 'maybe', [44, 43, 42, 39, 36, 35, 31], 4, 5, null],
        ];
    }

    #[DataProvider('hideOptions')]
    public function test_hiding_uses_preserves_the_distinct_counts_and_pre_hide_use_count(
        string $online, string $duplicate, array $ids, int $groups, int $combinations, ?int $dateCount
    ): void {
        $this->db->query('DROP TABLE item_tags');
        $session = [];
        $view = $this->page()->read('POST', [
            'type' => ['1'], 'minimumDate' => '2026-05-19', 'showAttributes' => 'YES',
            'hide_online_setting' => $online, 'hide_duplicate_group_settings' => $duplicate,
        ], $session);
        $table = $view['table'];
        $this->assertSame($ids, array_column($table['rows'], 'id'));
        $this->assertSame(7, $table['use_count']);
        $this->assertSame($groups, $table['group_and_setting_count']);
        $this->assertSame($combinations, $table['group_setting_item_count']);
        $this->assertSame($dateCount, $table['column_counts']['use_date']);
        $this->assertFalse($table['rows'][0]['candidate']);
        $this->assertSame('', $table['rows'][0]['sweet_spot']);
        $this->assertNull($table['rows'][0]['candidate_spot']);
    }

    public function test_row_text_and_attributes_use_recorded_people_and_the_owners_items(): void
    {
        $session = [];
        $view = $this->page()->read('POST', ['type' => ['1'], 'showAttributes' => 'yes'], $session);
        $rows = array_column($view['table']['rows'], null, 'id');
        $this->assertSame([
            'id' => 39,
            'use_date' => '2026-05-20',
            'item_id' => 10,
            'item_title' => 'Catan & <Sea>',
            'item_type' => 'board-game',
            'setting' => 'home',
            'person_count' => 3,
            'group_setting' => '03: Ann Dee, Ben Cole,  at home (Catan & <Sea> on 2026-05-20)',
            'candidate' => true,
            'sweet_spot' => '03,04 & <5>',
            'candidate_spot' => 'Alpha & <Spot>',
        ], $rows[39]);
        $this->assertFalse($rows[36]['candidate']);
        $this->assertNull($rows[36]['candidate_spot']);
        $this->assertSame('02: Ann Dee, Ben Cole online (Catan & <Sea> on 2026-05-21)', $rows[42]['group_setting']);
        $this->assertSame('02: Ann Dee, Ben Cole at Online (Catan & <Sea> on 2026-05-22)', $rows[43]['group_setting']);
        $this->assertSame('00:  at Library & <Desk> (Catan & <Sea> on 2026-05-18)', $rows[45]['group_setting']);
    }

    public function test_a_group_of_ten_people_has_no_leading_zero(): void
    {
        $this->db->query("INSERT INTO players VALUES
            (1, 1, 'P1', 'L1'), (3, 1, 'P3', 'L3'), (4, 1, 'P4', 'L4'), (5, 1, 'P5', 'L5'),
            (6, 1, 'P6', 'L6'), (7, 1, 'P7', 'L7'), (8, 1, 'P8', 'L8'), (9, 1, 'P9', 'L9')");
        $this->db->query('INSERT INTO uses_players (use_id, player_id, user_id)
            SELECT 45, id, 1 FROM players WHERE id <= 10 ORDER BY id DESC');

        $session = [];
        $view = $this->page()->read('POST', ['type' => ['1']], $session);
        $rows = array_column($view['table']['rows'], null, 'id');
        $this->assertSame(
            '10: P1 L1, Ann Dee, P3 L3, P4 L4, P5 L5, P6 L6, P7 L7, P8 L8, P9 L9, Ben Cole at Library & <Desk> (Catan & <Sea> on 2026-05-18)',
            $rows[45]['group_setting']
        );
    }

    public function test_the_route_orders_every_rendered_layout(): void
    {
        $this->withRoute(function (LocalPhpServer $server): void {
            foreach (['no', 'yes'] as $attributes) {
                foreach (['no', 'yes'] as $online) {
                    foreach (['no', 'yes'] as $duplicate) {
                        $html = $this->post($server, [
                            'minimumDate' => '2026-05-19',
                            'showAttributes' => $attributes,
                            'hide_online_setting' => $online,
                            'hide_duplicate_group_settings' => $duplicate,
                        ]);
                        $xpath = $this->xpath($html);
                        $columns = $xpath->query('//table[@id="uses"]/thead/tr/th');
                        $this->assertSame($attributes === 'yes' ? 9 : 5, $columns->length, $html);
                        $filtered = $online === 'yes' || $duplicate === 'yes';
                        $order = $filtered
                            ? ($attributes === 'yes' ? [[5, 'desc'], [0, 'desc']] : [[0, 'desc']])
                            : [[0, 'desc'], [2, 'asc']];
                        $this->assertSame(1, preg_match('/\border:\s*(\[.*?)\s*\}\);/s', $html, $config));
                        preg_match_all('/\[\s*(\d+)\s*,\s*[\'"](asc|desc)[\'"]\s*\]/', $config[1], $pairs, PREG_SET_ORDER);
                        $this->assertSame($order, array_map(fn ($pair) => [(int) $pair[1], $pair[2]], $pairs),
                            "Attributes $attributes, hide online $online, hide duplicates $duplicate");

                        $combinations = $online === 'yes' ? 4 : 5;
                        $groupLabel = $filtered ? "Group Setting ($combinations)" : 'Group Setting';
                        $dateLabel = $duplicate === 'yes' ? 'Interaction Date' : 'Interaction Date (7)';
                        $labels = [$dateLabel, 'Item', $groupLabel, 'Type', 'Setting'];
                        if ($attributes === 'yes') {
                            array_push($labels, 'Candidate', 'SwS', 'User Count', 'Candidate Spot Used');
                        }
                        $this->assertSame($labels, array_map(fn ($column) => trim($column->textContent), iterator_to_array($columns)));
                        $this->assertSame(0, $xpath->query('//table[@id="uses"]/tbody/tr[count(td) != ' . count($labels) . ']')->length);
                        $this->assertSame(
                            $filtered ? "Item Interactions ($combinations group settings)" : 'Item Interactions',
                            $xpath->evaluate('normalize-space(//main//h1)')
                        );
                        $this->assertStringContainsString("Group, setting, and game combinations: $combinations", $html);
                        $this->assertStringContainsString('Group and setting combinations: ' . ($online === 'yes' ? 3 : 4), $html);
                    }
                }
            }
        });
    }

    public function test_the_route_keeps_the_invalid_date_form_and_omits_the_table_and_summaries(): void
    {
        $this->db->query('DROP TABLE item_tags');
        $this->withRoute(function (LocalPhpServer $server): void {
            $html = $this->post($server, [
                'minimumDate' => '<bad & date>', 'showAttributes' => 'yes', 'hide_online_setting' => 'yes',
            ]);
            $xpath = $this->xpath($html);
            $this->assertSame('<bad & date>', $xpath->evaluate('string(//input[@id="minimumDate"]/@value)'));
            $this->assertSame('Enter a valid date in YYYY-MM-DD format.', $xpath->evaluate('normalize-space(//p[@class="form-error"])'));
            $this->assertSame(3, $xpath->query('//form//input[@checked]')->length);
            $this->assertSame(0, $xpath->query('//table[@id="uses"]')->length);
            $this->assertStringNotContainsString('combinations:', $html);
            $this->assertStringNotContainsString('new DataTable(', $html);
        });
    }

    public function test_the_route_escapes_row_text_once_and_respects_guest_links(): void
    {
        $this->withRoute(function (LocalPhpServer $server): void {
            foreach (['owner', 'guest'] as $viewer) {
                $html = $this->post($server, ['showAttributes' => 'yes', 'user_id' => '2'], $viewer);
                $xpath = $this->xpath($html);
                $rows = $xpath->query('//table[@id="uses"]/tbody/tr');
                $this->assertSame(8, $rows->length);
                $dateLink = $xpath->evaluate('string(td[@class="date"]/a/@href)', $rows[3]);
                $this->assertSame($viewer === 'guest' ? '' : '/uses/record-edit.php?id=39', $dateLink);
                $itemLink = $xpath->evaluate('string(td[@class="title"]/a/@href)', $rows[3]);
                $this->assertSame($viewer === 'guest' ? '/artifacts/show.php?id=10' : '/artifacts/edit.php?id=10', $itemLink);
                $this->assertSame('Catan & <Sea>', $xpath->evaluate('normalize-space(td[@class="title"])', $rows[3]));
                $this->assertSame(
                    '03: Ann Dee, Ben Cole, at home (Catan & <Sea> on 2026-05-20)',
                    $xpath->evaluate('normalize-space(td[@class="group_setting"])', $rows[3])
                );
                $this->assertSame('Yes', $xpath->evaluate('normalize-space(td[@class="candidate"])', $rows[3]));
                $this->assertSame('03,04 & <5>', $xpath->evaluate('normalize-space(td[@class="sweet_spot"])', $rows[3]));
                $this->assertSame('Alpha & <Spot>', $xpath->evaluate('normalize-space(td[@class="canidate_spot_used"])', $rows[3]));
                $this->assertSame('Library & <Desk>', $xpath->evaluate('normalize-space(td[@class="setting"])', $rows[7]));
            }
            $anonymous = $server->request(self::ROUTE);
            $this->assertSame(302, $anonymous['status']);
            $this->assertStringContainsString('Location: /login.php?redirectURL=%2Fuses%2Finteractions.php', $anonymous['headers']);
        });
    }

    private function page(): ItemInteractionsPage
    {
        return new ItemInteractionsPage($this->db, 1, '2026-06-01');
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

    private function withRoute(callable $exercise): void
    {
        $root = sys_get_temp_dir() . '/keeplore-interactions-' . bin2hex(random_bytes(6));
        mkdir($root . '/ui/uses', 0777, true);
        mkdir($root . '/private');
        $server = null;
        try {
            // Keep the actual route and bootstrap, with configuration isolated
            // from the checkout and the dependencies available to PHPUnit.
            copy(PRIVATE_PATH . '/initialize.php', $root . '/private/initialize.php');
            foreach (glob(PRIVATE_PATH . '/*.php') as $source) {
                $file = basename($source);
                if (!in_array($file, ['initialize.php', 'environment_variables.php'], true)) {
                    symlink($source, $root . '/private/' . $file);
                }
            }
            foreach (['classes', 'shared'] as $directory) {
                symlink(PRIVATE_PATH . '/' . $directory, $root . '/private/' . $directory);
            }
            symlink(PROJECT_PATH . '/vendor', $root . '/private/vendor');
            $config = [
                'DB_SERVER' => getenv('KEEPLORE_TEST_DB_HOST'),
                'DB_USER' => getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
                'DB_PASS' => getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
                'DB_NAME' => $this->db->query('SELECT DATABASE()')->fetch_row()[0],
                'DB_PORT' => (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306),
                'COOKIE_SECURE' => false,
                'ARTIFACTS_DOMAIN' => '',
                'API_ORIGIN' => '127.0.0.1',
            ];
            $environment = '<?php foreach (' . var_export($config, true) . ' as $name => $value) define($name, $value);';
            file_put_contents($root . '/private/environment_variables.php', $environment);
            $session = <<<'PHP'
                <?php
                session_start();
                $_SESSION = match ($_SERVER['HTTP_X_TEST_VIEWER'] ?? '') {
                    'guest' => ['user_id' => 1, 'guest_mode' => true],
                    'owner' => ['user_id' => 1, 'player_id' => 2, 'logged_in' => true, 'username' => 'Owner'],
                    default => [],
                };
                $_SESSION['csrf_token'] = 'fixture-token';
                session_write_close();
                PHP;
            $server = new LocalPhpServer($session . 'chdir(' . var_export($root . '/ui/uses', true) . ');'
                . 'require ' . var_export(PROJECT_PATH . '/ui' . self::ROUTE, true) . ';');
            $exercise($server);
        } finally {
            $server?->stop();
            foreach (glob($root . '/private/*') as $file) {
                unlink($file);
            }
            foreach (['/cache', '/logs', '/ui/uses', '/ui', '/private', ''] as $directory) {
                if (is_dir($root . $directory)) {
                    rmdir($root . $directory);
                }
            }
        }
    }

    private function post(LocalPhpServer $server, array $fields, string $viewer = 'owner'): string
    {
        $response = $server->request(self::ROUTE, [
            'Content-Type: application/x-www-form-urlencoded', 'X-Test-Viewer: ' . $viewer,
        ], 'POST', http_build_query($fields + ['type' => ['1'], 'csrf_token' => 'fixture-token']));
        $this->assertSame(200, $response['status'], $response['body']);
        return $response['body'];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
    }
}
