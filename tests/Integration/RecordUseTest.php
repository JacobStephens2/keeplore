<?php

namespace Tests\Integration;

use RecordUse;
use Uses;
use Tests\Support\LocalPhpServer;

require_once __DIR__ . '/MySqlTestCase.php';
require_once PRIVATE_PATH . '/record_use.php';
require_once __DIR__ . '/../Support/LocalPhpServer.php';

final class RecordUseTest extends MySqlTestCase
{
    private RecordUse $recordUse;
    private Uses $uses;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->recordUse = new RecordUse($this->db, 1, '2026-10-03');
        $this->uses = new Uses($this->db, 1);
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

    private function post(array $changes = []): array
    {
        return array_replace([
            'artifact' => ['id' => '10', 'name' => 'Posted Catan'],
            'user' => [['id' => '100', 'name' => ' Sam Lee '], ['id' => '101', 'name' => 'Jo Smith']],
            'useDate' => '2026-10-02',
            'Note' => "Grandma's",
            'NotesTwo' => 'Close game',
            'useCount' => '2',
        ], $changes);
    }

    public function test_plain_submit_records_uses_and_answers_with_the_message_and_use_group(): void
    {
        $answer = $this->recordUse->submit($this->post(), false);

        $this->assertSame([
            'status' => 302,
            'json' => null,
            'redirect' => '/uses/record-new.php',
            'session' => [
                'message' => 'The interaction with Posted Catan with 2 people was recorded 2 times.',
                'record_use_group' => [
                    'people' => [['id' => 100, 'name' => 'Sam Lee'], ['id' => 101, 'name' => 'Jo Smith']],
                    'useDate' => '2026-10-02',
                    'Note' => "Grandma's",
                    'savedOn' => '2026-10-03',
                ],
            ],
        ], $answer);
        $saved = $this->uses->all(['since' => '2026-10-02']);
        $this->assertCount(2, $saved);
        foreach ($saved as $use) {
            $this->assertSame(10, $use['item_id']);
            $this->assertSame('2026-10-02', $use['use_date']);
            $this->assertSame("Grandma's", $use['setting']);
            $this->assertSame('Close game', $use['notes']);
            $this->assertSame([100, 101], array_column($use['people'], 'id'));
        }
        $session = array_replace(['record_use_group' => ['old'], 'unrelated' => 'kept'], $answer['session']);
        $this->assertSame($answer['session']['record_use_group'], $session['record_use_group']);
        $this->assertSame('kept', $session['unrelated']);
    }

    public function test_ajax_submit_returns_the_last_new_use_and_the_refreshed_queue_without_session_updates(): void
    {
        $answer = $this->recordUse->submit($this->post(), true);

        $this->assertSame([
            'status' => 200,
            'json' => [
                'ok' => true,
                'message' => 'The interaction with Posted Catan with 2 people was recorded 2 times.',
                'artifact_id' => 10,
                'artifact_name' => 'Posted Catan',
                'artifact_type' => 'board-game',
                'use_id' => 3,
                'use_date' => '2026-10-02',
                'new_use_by_date' => '2027-03-31',
                'most_recent_use_date' => '2026-10-02',
                'is_overdue' => false,
            ],
            'redirect' => null,
            'session' => [],
        ], $answer);
        $this->assertSame(3, $this->uses->find($answer['json']['use_id'])['id']);
        $this->assertCount(2, $this->uses->all(['since' => '2026-10-02']));
        $this->assertSame([100, 101], array_column($this->uses->find(3)['people'], 'id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPosts')]
    public function test_invalid_submits_write_nothing_and_preserve_the_remembered_group(array $changes, string $message): void
    {
        $before = $this->uses->all();
        foreach ([true, false] as $ajax) {
            $answer = $this->recordUse->submit($this->post($changes), $ajax);
            $this->assertSame($ajax ? [
                'status' => 400, 'json' => ['ok' => false, 'message' => $message],
                'redirect' => null, 'session' => [],
            ] : [
                'status' => 302, 'json' => null, 'redirect' => '/uses/record-new.php',
                'session' => ['message' => $message],
            ], $answer);
            $session = array_replace(['record_use_group' => ['old']], $answer['session']);
            $this->assertSame(['old'], $session['record_use_group']);
            $this->assertSame($before, $this->uses->all());
            $this->assertSame('0', $this->db->query('SELECT COUNT(*) FROM uses_players')->fetch_row()[0]);
        }
    }

    public static function invalidPosts(): array
    {
        return [
            'missing Item' => [['artifact' => ['id' => '', 'name' => '']], 'Please choose an item.'],
            'foreign Item' => [['artifact' => ['id' => 20]], 'Choose an item from your own items.'],
            'unknown Item' => [['artifact' => ['id' => 999]], 'Choose an item from your own items.'],
            'foreign Person after valid Person' => [['user' => [['id' => 100], ['id' => 200]]], 'Choose people from your own people list.'],
            'unknown Person' => [['user' => [['id' => 999]]], 'Choose people from your own people list.'],
            'negative Person' => [['user' => [['id' => -1]]], 'Choose people from your own people list.'],
            'non scalar Person' => [['user' => [['id' => [100]]]], 'Choose people from your own people list.'],
            'impossible date' => [['useDate' => '2026-02-30'], 'Enter a valid date in YYYY-MM-DD format.'],
            'missing date' => [['useDate' => ''], 'Enter a valid date in YYYY-MM-DD format.'],
            'non text Setting' => [['Note' => ['x']], 'Enter text for the Setting and notes.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('returns')]
    public function test_return_markers_select_the_destination_and_independently_control_group_replacement(
        array $return, string $destination, bool $remember
    ): void {
        foreach ([false, true] as $invalid) {
            $answer = $this->recordUse->submit($this->post($return + ($invalid ? ['useDate' => 'bad'] : [])), false);
            $this->assertSame($destination, $answer['redirect']);
            $this->assertSame(302, $answer['status']);
            $this->assertNull($answer['json']);
            $this->assertSame($remember && !$invalid, array_key_exists('record_use_group', $answer['session']));
            $session = array_replace(['record_use_group' => ['old']], $answer['session']);
            if (!$remember || $invalid) {
                $this->assertSame(['old'], $session['record_use_group']);
                $this->assertSame(['message'], array_keys($answer['session']));
            }
        }
    }

    public static function returns(): array
    {
        return [
            'Edit User positive' => [['return_to' => 'user-edit', 'return_player_id' => '101'], '/users/edit.php?id=101', false],
            'Edit User zero' => [['return_to' => 'user-edit', 'return_player_id' => '0'], '/uses/record-new.php', false],
            'Edit User negative' => [['return_to' => 'user-edit', 'return_player_id' => '-1'], '/uses/record-new.php', false],
            'Edit User missing' => [['return_to' => 'user-edit'], '/uses/record-new.php', false],
            'other marker' => [['return_to' => 'other', 'return_player_id' => '101'], '/uses/record-new.php', true],
            'no marker' => [[], '/uses/record-new.php', true],
        ];
    }

    public function test_the_group_keeps_positive_distinct_people_with_the_first_trimmed_name_and_posted_spelling(): void
    {
        $answer = $this->recordUse->submit($this->post([
            'user' => [
                ['id' => '', 'name' => 'Typed but not picked'],
                ['id' => '100', 'name' => ' First posted name '],
                ['id' => 100, 'name' => 'Later name'],
                ['id' => '101', 'name' => ''],
            ],
            'Note' => '  Cabin  ',
        ]), false);

        $this->assertSame([
            'people' => [['id' => 100, 'name' => 'First posted name'], ['id' => 101, 'name' => '']],
            'useDate' => '2026-10-02', 'Note' => '  Cabin  ', 'savedOn' => '2026-10-03',
        ], $answer['session']['record_use_group']);
        $this->assertSame('The interaction with Posted Catan with 4 people was recorded 2 times.', $answer['session']['message']);
        $this->assertSame([100, 101], array_column($this->uses->find(3)['people'], 'id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function test_number_of_uses_defaults_and_limits_match_both_the_saved_uses_and_message(array $changes, int $count): void
    {
        $post = $this->post($changes);
        if ($changes === []) {
            unset($post['useCount']);
        }
        $answer = $this->recordUse->submit($post, true);
        $this->assertCount($count, $this->uses->all(['since' => '2026-10-02']));
        $this->assertSame($count + 1, $answer['json']['use_id']);
        $this->assertSame('The interaction with Posted Catan with 2 people was recorded'
            . ($count === 1 ? '.' : " $count times."), $answer['json']['message']);
    }

    public static function counts(): array
    {
        return [
            'missing' => [[], 1], 'blank' => [['useCount' => ''], 1],
            'not numeric' => [['useCount' => 'abc'], 1], 'zero' => [['useCount' => '0'], 1],
            'negative' => [['useCount' => '-3'], 1], 'two' => [['useCount' => '2'], 2],
            'maximum' => [['useCount' => '20'], 20], 'over maximum' => [['useCount' => '500'], 20],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('laterHistory')]
    public function test_a_backdated_ajax_save_keeps_a_later_use_or_legacy_play_as_last_use(string $history): void
    {
        $this->runSql($history);
        $answer = $this->recordUse->submit($this->post(['useDate' => '2026-01-02', 'useCount' => '1']), true);

        $this->assertSame('2026-01-02', $answer['json']['use_date']);
        $this->assertSame('2026-02-01', $answer['json']['most_recent_use_date']);
        $this->assertSame('2026-07-31', $answer['json']['new_use_by_date']);
        $this->assertTrue($answer['json']['is_overdue']);
        $this->assertSame('2026-01-02', $this->uses->find($answer['json']['use_id'])['use_date']);
        $this->assertSame([], $answer['session']);
    }

    public static function laterHistory(): array
    {
        return [
            'later Use' => ["DELETE FROM responses"],
            'later legacy play' => ["UPDATE uses SET use_date = '2026-01-01';
                INSERT INTO responses (Title, user_id, PlayDate) VALUES (10, 1, '2026-02-01')"],
        ];
    }

    public function test_ajax_refreshes_an_item_that_is_no_longer_kept_and_preserves_empty_payload_defaults(): void
    {
        $this->db->query('UPDATE games SET type_id = NULL, Acq = NULL WHERE id = 13');
        $answer = $this->recordUse->submit($this->post([
            'artifact' => ['id' => '13'], 'user' => [], 'useCount' => '1',
        ]), true);

        $this->assertSame([
            'ok' => true, 'message' => 'The interaction with  with 0 people was recorded.',
            'artifact_id' => 13, 'artifact_name' => '', 'artifact_type' => '', 'use_id' => 2,
            'use_date' => '2026-10-02', 'new_use_by_date' => '2027-03-31',
            'most_recent_use_date' => '2026-10-02', 'is_overdue' => false,
        ], $answer['json']);
        $this->assertSame([], $answer['session']);
        $this->assertSame([], $this->uses->find(2)['people']);
    }

    public function test_an_empty_group_replaces_the_previous_group_and_an_omitted_day_uses_the_app_day(): void
    {
        $dayBefore = app_today();
        $answer = (new RecordUse($this->db, 1))->submit($this->post(['user' => [], 'useCount' => '1']), false);
        $this->assertContains($answer['session']['record_use_group']['savedOn'], [$dayBefore, app_today()]);
        $this->assertSame([], $answer['session']['record_use_group']['people']);
        $this->assertSame('The interaction with Posted Catan with 0 people was recorded.', $answer['session']['message']);
    }

    public function test_a_queue_read_failure_propagates_after_the_uses_have_committed(): void
    {
        $this->db->query('DROP TABLE users');
        try {
            $this->recordUse->submit($this->post(), true);
            $this->fail('The failed queue read must propagate.');
        } catch (\mysqli_sql_exception $error) {
            $this->assertStringContainsString('users', $error->getMessage());
        }
        $this->assertCount(2, $this->uses->all(['since' => '2026-10-02']));
    }

    public function test_submit_reads_no_request_globals_and_does_not_mutate_the_session(): void
    {
        $previousPost = $_POST;
        $previousSession = $_SESSION;
        try {
            $_POST = $this->post(['artifact' => ['id' => 20]]);
            $_SESSION = ['user_id' => 2, 'record_use_group' => ['old']];
            foreach ([false, true] as $ajax) {
                $answer = $this->recordUse->submit($this->post(), $ajax);
                $this->assertSame($ajax ? 200 : 302, $answer['status']);
                $this->assertSame(['user_id' => 2, 'record_use_group' => ['old']], $_SESSION);
            }
            $this->assertSame([], (new Uses($this->db, 2))->all());
        } finally {
            $_POST = $previousPost;
            $_SESSION = $previousSession;
        }
    }

    public function test_the_http_emitter_sends_ajax_json_and_preserves_session_keys_on_success_and_failure(): void
    {
        $server = new LocalPhpServer($this->httpRouter());
        $headers = ['Cookie: PHPSESSID=recorduseajax', 'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest'];
        try {
            $post = $this->post(['useCount' => '1', 'user' => [['id' => 100, 'name' => 'Sam']]]);
            $response = $server->request('/submit', $headers, 'POST', http_build_query($post));
            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('Content-Type: application/json', $response['headers']);
            $this->assertSame([
                'ok' => true, 'message' => 'The interaction with Posted Catan with 1 person was recorded.',
                'artifact_id' => 10, 'artifact_name' => 'Posted Catan', 'artifact_type' => 'board-game',
                'use_id' => 2, 'use_date' => '2026-10-02', 'new_use_by_date' => '2027-03-31',
                'most_recent_use_date' => '2026-10-02', 'is_overdue' => false,
            ], json_decode($response['body'], true));
            $post['useDate'] = 'bad';
            $failure = $server->request('/submit', $headers, 'POST', http_build_query($post));
            $this->assertSame(400, $failure['status']);
            $this->assertSame(['ok' => false, 'message' => 'Enter a valid date in YYYY-MM-DD format.'], json_decode($failure['body'], true));
            $session = $server->request('/session', $headers);
            $this->assertSame(['record_use_group' => ['old'], 'message' => 'prior', 'unrelated' => 'kept'], json_decode($session['body'], true));
            $this->assertCount(1, $this->uses->all(['since' => '2026-10-02']));
        } finally {
            $server->stop();
        }
    }

    public function test_the_http_emitter_redirects_and_applies_only_returned_session_updates(): void
    {
        $server = new LocalPhpServer($this->httpRouter());
        $headers = ['Cookie: PHPSESSID=recorduseplain', 'Content-Type: application/x-www-form-urlencoded'];
        try {
            $response = $server->request('/submit', $headers, 'POST', http_build_query($this->post()));
            $this->assertSame(302, $response['status']);
            $this->assertStringContainsString('Location: /keeplore/uses/record-new.php', $response['headers']);
            $saved = json_decode($server->request('/session', $headers)['body'], true);
            $this->assertSame([
                'people' => [['id' => 100, 'name' => 'Sam Lee'], ['id' => 101, 'name' => 'Jo Smith']],
                'useDate' => '2026-10-02', 'Note' => "Grandma's", 'savedOn' => '2026-10-03',
            ], $saved['record_use_group']);
            $this->assertSame('The interaction with Posted Catan with 2 people was recorded 2 times.', $saved['message']);
            $this->assertSame('kept', $saved['unrelated']);
            foreach (['101', '0', '-1', null] as $returnId) {
                $post = $this->post(['return_to' => 'user-edit', 'return_player_id' => $returnId, 'user' => []]);
                $response = $server->request('/submit', $headers, 'POST', http_build_query($post));
                $this->assertSame(302, $response['status']);
                $this->assertStringContainsString('Location: /keeplore/' . ($returnId === '101' ? 'users/edit.php?id=101' : 'uses/record-new.php'), $response['headers']);
                $this->assertSame($saved['record_use_group'], json_decode($server->request('/session', $headers)['body'], true)['record_use_group']);
            }
            $failure = $server->request('/submit', $headers, 'POST', http_build_query($this->post(['useDate' => 'bad'])));
            $this->assertSame(302, $failure['status']);
            $this->assertStringContainsString('Location: /keeplore/uses/record-new.php', $failure['headers']);
            $after = json_decode($server->request('/session', $headers)['body'], true);
            $this->assertSame($saved['record_use_group'], $after['record_use_group']);
            $this->assertSame('Enter a valid date in YYYY-MM-DD format.', $after['message']);
        } finally {
            $server->stop();
        }
    }

    private function httpRouter(): string
    {
        $module = var_export(PRIVATE_PATH . '/record_use.php', true);
        $functions = var_export(PRIVATE_PATH . '/functions.php', true);
        $host = var_export(getenv('KEEPLORE_TEST_DB_HOST'), true);
        $user = var_export(getenv('KEEPLORE_TEST_DB_USER') ?: 'root', true);
        $password = var_export(getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '', true);
        $port = (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306);
        $database = var_export($this->db->query('SELECT DATABASE()')->fetch_row()[0], true);
        return <<<PHP
<?php
define('WWW_ROOT', '/keeplore');
require_once $functions;
require_once $module;
session_start();
\$_SESSION += ['record_use_group' => ['old'], 'message' => 'prior', 'unrelated' => 'kept'];
if (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/session') {
    header('Content-Type: application/json');
    echo json_encode(\$_SESSION);
    return;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
\$db = new mysqli($host, $user, $password, $database, $port);
\$ajax = strtolower(\$_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
emit_record_use_response((new RecordUse(\$db, 1, '2026-10-03'))->submit(\$_POST, \$ajax));
PHP;
    }
}
