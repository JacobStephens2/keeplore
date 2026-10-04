<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: answer_quick_item_action(), which answers Snooze, Mark kept and Mark
 * to get rid of for one of the owner's Items. The result is the status, the
 * JSON body and the app path to go back to; nothing is sent.
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
        ];
    }

    private function answer(string $action, array $fields, int $ownerId = 1): array
    {
        return answer_quick_item_action($this->db, $ownerId, $action, $this->request($fields));
    }

    private function column(int $id, string $column): mixed
    {
        return $this->db->query("SELECT `$column` FROM games WHERE id = $id")->fetch_row()[0];
    }

    private function inDays(int $days): string
    {
        return (new \DateTime('today'))->modify("+$days days")->format('Y-m-d');
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
}
