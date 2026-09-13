<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProposalOutcomes;

final class ProposalOutcomesTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private ProposalOutcomes $proposals;

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
        $migration = PROJECT_PATH . '/database/migrations/add-proposal-outcomes.sql';
        if (is_file($migration)) {
            $this->runSql(file_get_contents($migration));
        }
        $module = PRIVATE_PATH . '/classes/ProposalOutcomes.php';
        if (is_file($module)) {
            require_once $module;
        }
        $this->proposals = new ProposalOutcomes($this->db, 1);
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

    private function proposal(array $changes = []): array
    {
        return array_replace([
            'item_id' => 10,
            'proposal_date' => '2026-09-12',
            'outcome' => 'explicit_decline',
            'note' => 'Too long tonight',
            'participant_ids' => [],
            'chosen_item_id' => null,
            'chosen_item_name' => '',
        ], $changes);
    }

    public function test_recording_a_decline_makes_it_readable(): void
    {
        $id = $this->proposals->save($this->proposal());
        $record = $this->proposals->find($id);

        $this->assertSame(10, $record['item_id']);
        $this->assertSame('2026-09-12', $record['proposal_date']);
        $this->assertSame('explicit_decline', $record['outcome']);
        $this->assertSame('Too long tonight', $record['note']);
    }

    public function test_a_group_proposal_keeps_participants_and_the_chosen_item(): void
    {
        $id = $this->proposals->save($this->proposal([
            'outcome' => 'chose_something_else',
            'participant_ids' => [100, 101, 100],
            'chosen_item_id' => 11,
        ]));
        $record = $this->proposals->find($id);

        $this->assertSame([100, 101], array_column($record['participants'], 'id'));
        $this->assertSame('Sam Lee', $record['participants'][0]['name']);
        $this->assertSame(11, $record['chosen_item_id']);
        $this->assertSame('Azul', $record['chosen_item_name']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignReferences')]
    public function test_recording_rejects_references_to_another_accounts_data(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->proposals->save($this->proposal($changes));
    }

    public static function foreignReferences(): array
    {
        return [
            'proposed item' => [['item_id' => 20]],
            'chosen item' => [['chosen_item_id' => 20]],
            'participant' => [['participant_ids' => [100, 200]]],
            'missing item' => [['item_id' => 999]],
        ];
    }

    public function test_history_reflects_corrections_and_deletion(): void
    {
        $id = $this->proposals->save($this->proposal(['participant_ids' => [100]]));
        $this->proposals->save($this->proposal([
            'proposal_date' => '2026-09-01',
            'outcome' => 'chose_something_else',
            'note' => 'Corrected the date',
            'participant_ids' => [101],
            'chosen_item_name' => 'A friend’s game',
        ]), $id);

        $history = $this->proposals->history(10);
        $this->assertCount(1, $history);
        $this->assertSame('2026-09-01', $history[0]['proposal_date']);
        $this->assertSame('chose_something_else', $history[0]['outcome']);
        $this->assertSame('A friend’s game', $history[0]['chosen_item_name']);
        $this->assertSame([101], array_column($this->proposals->find($id)['participants'], 'id'));

        $this->proposals->delete($id);
        $this->assertNull($this->proposals->find($id));
        $this->assertSame([], $this->proposals->history(10));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProposals')]
    public function test_invalid_input_is_rejected_before_saving(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->proposals->save($this->proposal($changes));
    }

    public static function invalidProposals(): array
    {
        return [
            'impossible date' => [['proposal_date' => '2026-02-30']],
            'missing date' => [['proposal_date' => '']],
            'date includes time' => [['proposal_date' => '2026-09-12 12:00:00']],
            'unknown outcome' => [['outcome' => 'both']],
            'fractional item' => [['item_id' => '10.5']],
            'malformed participants' => [['participant_ids' => '100']],
            'malformed note' => [['note' => ['text']]],
            'same alternative' => [['chosen_item_id' => 10]],
            'long alternative name' => [['chosen_item_name' => str_repeat('x', 256)]],
        ];
    }

    public function test_rankings_count_each_proposal_once_and_keep_outcomes_separate(): void
    {
        $this->proposals->save($this->proposal(['participant_ids' => [100, 101], 'chosen_item_id' => 11]));
        $this->proposals->save($this->proposal(['outcome' => 'chose_something_else']));
        $this->proposals->save($this->proposal(['item_id' => 11]));
        $this->proposals->save($this->proposal(['item_id' => 11]));
        $this->proposals->save($this->proposal(['item_id' => 12, 'outcome' => 'chose_something_else']));
        $this->proposals->save($this->proposal(['item_id' => 13]));
        (new ProposalOutcomes($this->db, 2))->save($this->proposal(['item_id' => 20]));

        $rows = $this->proposals->report();
        $this->assertSame([11, 10, 12], array_column($rows, 'item_id'));
        $counts = array_column($rows, null, 'item_id');
        $this->assertSame(1, $counts[10]['explicit_declines']);
        $this->assertSame(1, $counts[10]['chose_something_else']);
        $this->assertSame(2, $counts[11]['explicit_declines']);
        $this->assertSame(1, $counts[12]['chose_something_else']);
    }

    public function test_report_filters_dates_inclusively_and_can_sort_all_items_by_alternative_count(): void
    {
        foreach (['2026-08-31', '2026-09-01', '2026-09-12', '2026-09-13'] as $date) {
            $this->proposals->save($this->proposal(['proposal_date' => $date]));
        }
        $this->proposals->save($this->proposal(['item_id' => 13, 'outcome' => 'chose_something_else']));
        $rows = $this->proposals->report('2026-09-01', '2026-09-12', true, 'chose_something_else', 'desc');

        $this->assertSame([13, 12, 11, 10], array_column($rows, 'item_id'));
        $this->assertSame(2, array_column($rows, null, 'item_id')[10]['explicit_declines']);
        $this->assertSame(0, array_column($rows, null, 'item_id')[11]['explicit_declines']);
        $ascending = $this->proposals->report('', '', false, 'explicit_declines', 'asc');
        $this->assertSame([12, 11, 10], array_column($ascending, 'item_id'));
    }

    public function test_another_account_cannot_read_edit_or_delete_a_proposal(): void
    {
        $id = $this->proposals->save($this->proposal());
        $other = new ProposalOutcomes($this->db, 2);
        $this->assertNull($other->find($id));
        $this->assertSame([], $other->history(10));
        foreach (['edit', 'delete'] as $action) {
            try {
                if ($action === 'edit') {
                    $other->save($this->proposal(['item_id' => 20]), $id);
                } else {
                    $other->delete($id);
                }
                $this->fail('Another account must not ' . $action . ' this proposal.');
            } catch (\OutOfBoundsException $expected) {
                $this->assertSame('explicit_decline', $this->proposals->find($id)['outcome']);
            }
        }
    }

    public function test_corrections_and_deletion_update_rankings(): void
    {
        $id = $this->proposals->save($this->proposal());
        $this->proposals->save($this->proposal(['outcome' => 'chose_something_else']), $id);
        $counts = array_column($this->proposals->report(), null, 'item_id')[10];
        $this->assertSame(0, $counts['explicit_declines']);
        $this->assertSame(1, $counts['chose_something_else']);
        $this->proposals->delete($id);
        $this->assertSame(0, array_column($this->proposals->report(), null, 'item_id')[10]['chose_something_else']);
    }

    public function test_proposals_do_not_change_either_items_use_history_or_due_date(): void
    {
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
        require_once PRIVATE_PATH . '/query_functions/response_queries.php';
        require_once PRIVATE_PATH . '/database.php';
        $GLOBALS['db'] = $this->db;
        $_SESSION['user_id'] = 1;
        $before = [compute_artifact_use_by_status(10, 1), compute_artifact_use_by_status(11, 1)];
        $usesBefore = find_uses_by_user_id([1, 2], '')->fetch_all(MYSQLI_ASSOC);
        $id = $this->proposals->save($this->proposal(['chosen_item_id' => 11]));
        $this->proposals->save($this->proposal(['chosen_item_id' => 11, 'outcome' => 'chose_something_else']), $id);
        $this->assertSame($before, [compute_artifact_use_by_status(10, 1), compute_artifact_use_by_status(11, 1)]);
        $this->assertSame($usesBefore, find_uses_by_user_id([1, 2], '')->fetch_all(MYSQLI_ASSOC));
        $this->proposals->delete($id);
        $this->assertSame($before, [compute_artifact_use_by_status(10, 1), compute_artifact_use_by_status(11, 1)]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReports')]
    public function test_report_rejects_invalid_filters(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->proposals->report(...$args);
    }

    public static function invalidReports(): array
    {
        return [
            [['2026-09-12', '2026-09-01']],
            [['2026-02-30']],
            [['', '', false, 'item_name; DROP TABLE games']],
            [['', '', false, 'item_name', 'sideways']],
        ];
    }

    public function test_a_failed_edit_preserves_the_original_proposal(): void
    {
        $id = $this->proposals->save($this->proposal(['participant_ids' => [100]]));
        $before = $this->proposals->find($id);
        // Simulate a storage failure after the main record update, during participant replacement.
        $this->db->query("CREATE TRIGGER fail_participant BEFORE INSERT ON proposal_outcome_players
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated write failure'");
        try {
            $this->proposals->save($this->proposal(['participant_ids' => [101], 'note' => 'Changed']), $id);
            $this->fail('The simulated write failure must surface.');
        } catch (\mysqli_sql_exception $expected) {
            $this->assertSame($before, $this->proposals->find($id));
        }
    }

    public function test_optional_fields_can_be_omitted_for_any_item_type(): void
    {
        $id = $this->proposals->save([
            'item_id' => 12,
            'proposal_date' => '2026-09-12',
            'outcome' => 'chose_something_else',
        ]);
        $record = $this->proposals->find($id);
        $this->assertSame([], $record['participants']);
        $this->assertNull($record['chosen_item_id']);
        $this->assertSame('', $record['chosen_item_name']);
        $this->assertSame('', $record['note']);
        $this->assertSame(1, array_column($this->proposals->report(), null, 'item_id')[12]['chose_something_else']);
    }

    public function test_rerunning_the_migration_preserves_recorded_proposals(): void
    {
        $id = $this->proposals->save($this->proposal(['participant_ids' => [100]]));
        $before = $this->proposals->find($id);
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-proposal-outcomes.sql'));
        $this->assertSame($before, $this->proposals->find($id));
    }

    public function test_an_item_without_a_type_can_be_opened_to_view_its_proposal_history(): void
    {
        $this->db->query("INSERT INTO games (id, user_id, Title) VALUES (14, 1, 'Uncategorized item')");
        $this->proposals->save($this->proposal(['item_id' => 14]));
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
        $GLOBALS['db'] = $this->db;
        $item = find_artifact_by_id(14);
        $this->assertNotNull($item, 'The item page must be able to load items without a type.');
        $this->assertSame('Uncategorized item', $item['Title']);
        $this->assertCount(1, $this->proposals->history($item['id']));
    }
}
