<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seams:
 * - answer_quick_item_action(), which answers Snooze, Mark kept and Mark to
 *   get rid of for one of the owner's Items. The result is the status, the
 *   JSON body and the app path to go back to; nothing is sent.
 * - quick_item_action_response(), which answers only a POST whose CSRF token
 *   matches the session. Any other request, including a Daily email link, is
 *   a page that does not change the Item.
 */
final class QuickItemActionsTest extends TestCase
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
        require_once PRIVATE_PATH . '/quick_item_actions.php';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SESSION = [];
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

    /** A request in quick_item_action_request_from_globals()'s shape. */
    private function request(array $fields = []): array
    {
        return $fields + [
            'artifact_id' => null,
            'value' => null,
            'days' => null,
            'return_to' => null,
            'is_ajax' => false,
            'method' => 'GET',
            'may_change' => false,
        ];
    }

    /** The change, as a POST whose CSRF token already matches the session. */
    private function answer(string $action, array $fields, int $ownerId = 1): array
    {
        return answer_quick_item_action($this->db, $ownerId, $action, $this->request($fields + [
            'method' => 'POST',
            'may_change' => true,
        ]));
    }

    private function column(int $id, string $column): mixed
    {
        return $this->db->query("SELECT `$column` FROM games WHERE id = $id")->fetch_row()[0];
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable(app_today()))->modify("+$days days")->format('Y-m-d');
    }

    public function test_snooze_hides_the_item_for_the_days_asked_for(): void
    {
        $answer = $this->answer('snooze', ['artifact_id' => 10, 'days' => 3]);

        $until = $this->inDays(3);
        $this->assertSame(200, $answer['status']);
        $this->assertSame([
            'ok' => true,
            'artifact_id' => 10,
            'artifact_name' => 'Catan',
            'snoozed_until' => $until,
            'message' => "Catan snoozed until $until.",
        ], $answer['body']);
        $this->assertSame($until, $this->column(10, 'snoozed_until'));
        $this->assertSame('/index.php#priority-queue', $answer['path']);
    }

    public function test_snooze_without_days_uses_the_owners_default_snooze_days(): void
    {
        $this->db->query('UPDATE users SET default_snooze_days = 5 WHERE id = 1');

        $answer = $this->answer('snooze', ['artifact_id' => 10]);

        $this->assertSame($this->inDays(5), $answer['body']['snoozed_until']);
        $this->assertSame($this->inDays(5), $this->column(10, 'snoozed_until'));
    }

    public function test_snooze_with_days_below_one_uses_the_owners_default_snooze_days(): void
    {
        $this->db->query('UPDATE users SET default_snooze_days = 5 WHERE id = 1');

        $this->assertSame($this->inDays(5), $this->answer('snooze', ['artifact_id' => 10, 'days' => 0])['body']['snoozed_until']);
        $this->assertSame($this->inDays(5), $this->answer('snooze', ['artifact_id' => 10, 'days' => -2])['body']['snoozed_until']);
    }

    public function test_mark_kept_keeps_the_item(): void
    {
        $answer = $this->answer('kept', ['artifact_id' => 12, 'value' => 1]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame([
            'ok' => true,
            'value' => 1,
            'is_kept' => 1,
            'artifact_id' => 12,
            'artifact_name' => 'Arrival',
            'message' => 'Arrival is now kept.',
        ], $answer['body']);
        $this->assertSame(1, (int) $this->column(12, 'is_kept'));
        $this->assertSame('/artifacts/useby.php', $answer['path']);
    }

    public function test_mark_kept_without_a_value_stops_keeping_the_item(): void
    {
        $answer = $this->answer('kept', ['artifact_id' => 10]);

        $this->assertSame([
            'ok' => true,
            'value' => 0,
            'is_kept' => 0,
            'artifact_id' => 10,
            'artifact_name' => 'Catan',
            'message' => 'Catan is no longer kept.',
        ], $answer['body']);
        $this->assertSame(0, (int) $this->column(10, 'is_kept'));
    }

    public function test_mark_to_get_rid_of_without_a_value_marks_the_item(): void
    {
        $answer = $this->answer('get-rid-of', ['artifact_id' => 10]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame([
            'ok' => true,
            'value' => 1,
            'artifact_id' => 10,
            'artifact_name' => 'Catan',
            'message' => 'Catan marked to get rid of.',
        ], $answer['body']);
        $this->assertSame(1, (int) $this->column(10, 'to_get_rid_of'));
        $this->assertSame('/artifacts/useby.php', $answer['path']);
    }

    public function test_mark_to_get_rid_of_with_value_zero_restores_the_item(): void
    {
        $answer = $this->answer('get-rid-of', ['artifact_id' => 11, 'value' => 0]);

        $this->assertSame([
            'ok' => true,
            'value' => 0,
            'artifact_id' => 11,
            'artifact_name' => 'Azul',
            'message' => 'Azul restored to collection.',
        ], $answer['body']);
        $this->assertSame(0, (int) $this->column(11, 'to_get_rid_of'));
    }

    public function test_another_owners_item_is_not_found_and_unchanged(): void
    {
        foreach (['snooze', 'kept', 'get-rid-of'] as $action) {
            $answer = $this->answer($action, ['artifact_id' => 20, 'value' => 1, 'return_to' => 'dashboard']);

            $this->assertSame(404, $answer['status'], $action);
            $this->assertSame(['ok' => false, 'message' => 'Item not found.'], $answer['body'], $action);
            $this->assertSame('/artifacts/index.php', $answer['path'], $action);
        }
        $this->assertSame(1, (int) $this->column(20, 'is_kept'));
        $this->assertSame(0, (int) $this->column(20, 'to_get_rid_of'));
        $this->assertNull($this->column(20, 'snoozed_until'));
    }

    public function test_a_missing_item_is_not_found(): void
    {
        $answer = $this->answer('kept', ['artifact_id' => 999, 'value' => 1]);

        $this->assertSame(404, $answer['status']);
        $this->assertSame(['ok' => false, 'message' => 'Item not found.'], $answer['body']);
        $this->assertSame('/artifacts/index.php', $answer['path']);
    }

    public function test_no_item_answers_400_and_goes_back_to_the_return_to_destination(): void
    {
        $expected = ['ok' => false, 'message' => 'No item specified.'];

        $answer = $this->answer('snooze', []);
        $this->assertSame(400, $answer['status']);
        $this->assertSame($expected, $answer['body']);
        $this->assertSame('/index.php#priority-queue', $answer['path']);

        $answer = $this->answer('kept', []);
        $this->assertSame(400, $answer['status']);
        $this->assertSame($expected, $answer['body']);
        $this->assertSame('/artifacts/useby.php', $answer['path']);

        $answer = $this->answer('get-rid-of', ['return_to' => 'to-get-rid-of']);
        $this->assertSame(400, $answer['status']);
        $this->assertSame($expected, $answer['body']);
        $this->assertSame('/artifacts/to-get-rid-of.php', $answer['path']);
    }

    public function test_every_action_honours_every_known_return_to(): void
    {
        $paths = [
            'dashboard' => '/index.php#priority-queue',
            'useby' => '/artifacts/useby.php',
            'index' => '/artifacts/index.php',
            'to-get-rid-of' => '/artifacts/to-get-rid-of.php',
            'new' => '/artifacts/new',
        ];
        foreach (['snooze', 'kept', 'get-rid-of'] as $action) {
            foreach ($paths as $returnTo => $path) {
                $answer = $this->answer($action, ['artifact_id' => 10, 'return_to' => $returnTo]);
                $this->assertSame($path, $answer['path'], "$action, return_to=$returnTo");
            }
        }
    }

    public function test_an_unknown_return_to_goes_to_the_actions_default(): void
    {
        $this->assertSame('/index.php#priority-queue', $this->answer('snooze', ['artifact_id' => 10, 'return_to' => 'elsewhere'])['path']);
        $this->assertSame('/artifacts/useby.php', $this->answer('kept', ['artifact_id' => 10, 'return_to' => 'elsewhere'])['path']);
        $this->assertSame('/artifacts/useby.php', $this->answer('get-rid-of', ['artifact_id' => 10, 'return_to' => 'elsewhere'])['path']);
    }

    public function test_an_unknown_action_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->answer('delete', ['artifact_id' => 10]);
    }

    public function test_a_bad_csrf_post_is_the_same_result_on_both_seams(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'tok';
        $_POST = ['csrf_token' => 'other', 'artifact_id' => '10'];
        $_REQUEST = $_POST;

        $request = quick_item_action_request_from_globals();
        $answer = answer_quick_item_action($this->db, 1, 'snooze', $request);
        $response = quick_item_action_response($this->db, 1, 'snooze', $request);

        $this->assertSame(['answer' => $answer], $response);
    }

    public function test_answering_a_get_does_not_change_the_item(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['artifact_id' => '10', 'days' => '3'];
        $_POST = [];
        $_REQUEST = $_GET;

        $answer = answer_quick_item_action($this->db, 1, 'snooze', quick_item_action_request_from_globals());

        $this->assertNull($this->column(10, 'snoozed_until'));
        $this->assertSame(405, $answer['status']);
        $this->assertSame([
            'ok' => false,
            'message' => 'Quick item actions accept a POST that has passed the CSRF check.',
        ], $answer['body']);
    }

    public function test_answering_a_post_with_the_wrong_csrf_token_does_not_change_the_item(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'tok';
        $_POST = ['csrf_token' => 'other', 'artifact_id' => '10'];
        $_REQUEST = $_POST;

        $answer = answer_quick_item_action($this->db, 1, 'get-rid-of', quick_item_action_request_from_globals());

        $this->assertSame(0, (int) $this->column(10, 'to_get_rid_of'));
        $this->assertSame(403, $answer['status']);
        $this->assertSame(['ok' => false, 'message' => 'Invalid CSRF token.'], $answer['body']);
    }

    public function test_a_daily_email_snooze_link_opens_a_page_and_does_not_snooze_the_item(): void
    {
        $response = $this->respond('snooze', 'GET', ['artifact_id' => '10', 'return_to' => 'useby']);

        $this->assertNull($this->column(10, 'snoozed_until'));
        $this->assertArrayNotHasKey('answer', $response);
        $page = $response['page'];
        $this->assertSame('Snooze', $page['title']);
        $html = $page['html'];
        $this->assertStringContainsString('Catan', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('action="/artifacts/snooze.php"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('name="artifact_id" value="10"', $html);
        $this->assertStringContainsString('name="return_to" value="useby"', $html);
        $this->assertStringContainsString('href="/artifacts/useby.php"', $html);
        $this->assertStringNotContainsString('name="days"', $html);
    }

    public function test_a_daily_email_to_get_rid_of_link_opens_a_page_and_does_not_mark_the_item(): void
    {
        $response = $this->respond('get-rid-of', 'GET', ['artifact_id' => '10', 'return_to' => 'useby']);

        $this->assertSame(0, (int) $this->column(10, 'to_get_rid_of'));
        $this->assertSame('To get rid of', $response['page']['title']);
        $html = $response['page']['html'];
        $this->assertStringContainsString('Catan', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('action="/artifacts/mark-get-rid-of.php"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringNotContainsString('name="value"', $html);
    }

    public function test_a_get_does_not_stop_keeping_the_item(): void
    {
        $response = $this->respond('kept', 'GET', ['artifact_id' => '10', 'return_to' => 'index']);

        $this->assertSame(1, (int) $this->column(10, 'is_kept'));
        $this->assertSame('Stop keeping', $response['page']['title']);
        $html = $response['page']['html'];
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('action="/artifacts/set-tracked.php"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringNotContainsString('name="value"', $html);
    }

    public function test_a_get_to_restore_an_item_names_restore_and_does_not_restore_it(): void
    {
        $response = $this->respond('get-rid-of', 'GET', [
            'artifact_id' => '11',
            'value' => '0',
            'return_to' => 'to-get-rid-of',
        ]);

        $this->assertSame(1, (int) $this->column(11, 'to_get_rid_of'));
        $this->assertSame('Restore', $response['page']['title']);
        $html = $response['page']['html'];
        $this->assertStringContainsString('Azul', $html);
        $this->assertStringContainsString('name="value" value="0"', $html);
        $this->assertStringContainsString('href="/artifacts/to-get-rid-of.php"', $html);
    }

    public function test_a_get_for_another_owners_item_opens_a_page_and_changes_nothing(): void
    {
        $response = $this->respond('snooze', 'GET', ['artifact_id' => '20']);

        $this->assertNull($this->column(20, 'snoozed_until'));
        $this->assertSame(1, (int) $this->column(20, 'is_kept'));
        $html = $response['page']['html'];
        $this->assertStringContainsString('Item not found.', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('Private item', $html);
    }

    public function test_the_page_escapes_the_return_to_it_posts_back(): void
    {
        $response = $this->respond('snooze', 'GET', ['artifact_id' => '10', 'return_to' => '"><script>']);

        $html = $response['page']['html'];
        $this->assertStringContainsString('name="return_to" value="&quot;&gt;&lt;script&gt;"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertNull($this->column(10, 'snoozed_until'));
    }

    public function test_a_post_without_the_sessions_csrf_token_does_not_snooze_the_item(): void
    {
        $response = $this->respond('snooze', 'POST', ['artifact_id' => '10', 'days' => '3'], 'other');

        $this->assertNull($this->column(10, 'snoozed_until'));
        $this->assertSame(403, $response['answer']['status']);
        $this->assertSame(['ok' => false, 'message' => 'Invalid CSRF token.'], $response['answer']['body']);
        $this->assertArrayNotHasKey('page', $response);
    }

    public function test_a_csrf_token_in_the_query_string_does_not_let_a_post_snooze_the_item(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'tok';
        $_GET = ['csrf_token' => 'tok'];
        $_POST = ['artifact_id' => '10', 'days' => '3'];
        $_REQUEST = ['csrf_token' => 'tok', 'artifact_id' => '10', 'days' => '3'];

        $response = quick_item_action_response($this->db, 1, 'snooze', quick_item_action_request_from_globals());

        $this->assertNull($this->column(10, 'snoozed_until'));
        $this->assertSame(403, $response['answer']['status']);
        $this->assertArrayNotHasKey('page', $response);
    }

    public function test_a_post_with_the_sessions_csrf_token_snoozes_the_item(): void
    {
        $response = $this->respond('snooze', 'POST', ['artifact_id' => '10', 'days' => '3', 'return_to' => 'useby']);

        $this->assertArrayNotHasKey('page', $response);
        $this->assertSame(200, $response['answer']['status']);
        $this->assertSame($this->inDays(3), $response['answer']['body']['snoozed_until']);
        $this->assertSame($this->inDays(3), $this->column(10, 'snoozed_until'));
    }

    public function test_a_post_with_the_sessions_csrf_token_marks_the_item_to_get_rid_of(): void
    {
        $response = $this->respond('get-rid-of', 'POST', ['artifact_id' => '10']);

        $this->assertSame(200, $response['answer']['status']);
        $this->assertSame(1, (int) $this->column(10, 'to_get_rid_of'));
    }

    public function test_a_post_with_the_sessions_csrf_token_stops_keeping_the_item(): void
    {
        $response = $this->respond('kept', 'POST', ['artifact_id' => '10']);

        $this->assertSame(200, $response['answer']['status']);
        $this->assertSame(0, (int) $this->column(10, 'is_kept'));
    }

    /**
     * The request the way quick_item_action_request_from_globals() reads it.
     * The session token is tok. A POST sends $fields and that token in the
     * body; a GET sends $fields in the query and no token.
     */
    private function respond(string $action, string $method, array $fields, ?string $postedToken = 'tok'): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SESSION['csrf_token'] = 'tok';
        if ($method === 'POST') {
            $_GET = [];
            $_POST = $fields;
            if ($postedToken !== null) {
                $_POST['csrf_token'] = $postedToken;
            }
            $_REQUEST = $_POST;
        } else {
            $_GET = $fields;
            $_POST = [];
            $_REQUEST = $fields;
        }
        return quick_item_action_response($this->db, 1, $action, quick_item_action_request_from_globals());
    }
}
