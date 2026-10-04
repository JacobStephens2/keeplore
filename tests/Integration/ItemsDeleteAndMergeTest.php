<?php

namespace Tests\Integration;

use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Items::delete and Items::merge, the two Items writes that reach
 * everything pointing at an Item. Delete takes the Item's Uses (with their
 * people), item proposals (with their participants), BGG ratings, legacy
 * plays, sweet spots, tags and Event plan entries with it, and a proposal
 * that chose it instead keeps the name but loses the link. Merge moves all
 * of it from the loser to the survivor, where the survivor's own tag,
 * rating or plan wins, and deletes the loser. Both are owner-scoped and
 * all-or-nothing.
 */
final class ItemsDeleteAndMergeTest extends TestCase
{
    /** Every table that points at an Item, with its Item column, checked independently of Items. */
    private const EXPECTED_ITEM_REFERENCES = [
        ['uses', 'artifact_id'],
        ['responses', 'Title'],
        ['sweetspots', 'Title'],
        ['proposal_outcomes', 'item_id'],
        ['proposal_outcomes', 'chosen_item_id'],
        ['item_tags', 'artifact_id'],
        ['item_bgg_ratings', 'artifact_id'],
        ['event_items', 'artifact_id'],
    ];

    private const TABLES = [
        'games', 'uses', 'uses_players', 'responses', 'sweetspots', 'proposal_outcomes',
        'proposal_outcome_players', 'item_tags', 'item_bgg_ratings', 'event_items',
    ];

    private ?\mysqli $db = null;
    private string $databaseName;
    private Items $items;

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
        $this->runSql($this->schemaTable('uses_players') . $this->schemaTable('sweetspots')
            . $this->schemaTable('proposal_outcomes') . $this->schemaTable('proposal_outcome_players')
            . $this->schemaTable('item_bgg_ratings'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-events.sql'));

        // Fixture items 10 (Catan), 11 (Azul) and 12 (Arrival) belong to
        // user 1, item 20 to user 2; the fixture records one Use of item 10.
        $this->runSql("
            INSERT INTO uses (id, artifact_id, user_id, use_date) VALUES
                (2, 11, 1, '2026-03-01'), (3, 11, 1, '2026-03-02'), (4, 20, 2, '2026-03-03');
            INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1), (2, 100, 1), (2, 101, 1), (4, 200, 2);
            INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2019-05-05'), (20, 2, '2019-06-06');
            INSERT INTO sweetspots (Title, SwS) VALUES (11, '3'), (20, '2');
            INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
                (1, 10, 'beach-safe'), (1, 11, 'beach-safe'), (1, 11, 'family'), (2, 20, 'mine');
            INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating) VALUES
                (1, 10, 'Gyges', 9.0), (1, 11, 'Gyges', 4.0), (1, 11, 'Other', 7.0), (2, 20, 'Gyges', 5.0);
            INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note, chosen_item_id, chosen_item_name) VALUES
                (1, 1, 11, '2026-04-01', 'explicit_decline', '', NULL, ''),
                (2, 1, 12, '2026-04-02', 'chose_something_else', 'Wanted tiles', 11, 'Azul'),
                (4, 2, 20, '2026-04-04', 'explicit_decline', '', NULL, '');
            INSERT INTO proposal_outcome_players (proposal_id, player_id) VALUES (1, 100), (2, 101), (4, 200);
            INSERT INTO events (id, user_id, name) VALUES (1, 1, 'Beach week'), (2, 1, 'Game night'), (3, 2, 'Their night');
            INSERT INTO event_items (event_id, artifact_id, note) VALUES
                (1, 10, 'survivor'), (1, 11, 'loser'), (2, 11, 'loser'), (3, 20, 'theirs');
        ");
        require_once PRIVATE_PATH . '/classes/Items.php';
        $this->items = new Items($this->db, 1);
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

    private function column(string $sql): array
    {
        return array_map(fn ($row) => $row[0], $this->db->query($sql)->fetch_all());
    }

    /** Every row of every table delete and merge touch. */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->db->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetch_all(MYSQLI_ASSOC);
        }
        return $rows;
    }

    private function assertNothingPointsAt(int $id): void
    {
        foreach (self::EXPECTED_ITEM_REFERENCES as [$table, $column]) {
            $this->assertSame(['0'], $this->column("SELECT COUNT(*) FROM {$table} WHERE {$column} = {$id}"), "{$table}.{$column} still points at item {$id}");
        }
    }

    private function assertRefusedWithoutChange(string $exception, callable $write): void
    {
        $before = $this->snapshot();
        try {
            $write();
            $this->fail("The write must throw {$exception}.");
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_delete_removes_the_item_and_everything_that_points_at_it(): void
    {
        $this->items->delete(11);

        $this->assertSame([], $this->column('SELECT id FROM games WHERE id = 11'));
        $this->assertNothingPointsAt(11);
        $this->assertSame(['1'], $this->column('SELECT use_id FROM uses_players WHERE user_id = 1'), "the deleted Uses' people go with them");
        $this->assertSame(['2'], $this->column('SELECT proposal_id FROM proposal_outcome_players WHERE player_id IN (100, 101)'), "the deleted proposals' participants go with them");
    }

    public function test_delete_leaves_the_owners_other_items_and_another_owners_items_alone(): void
    {
        $this->items->delete(11);

        $this->assertSame(['1'], $this->column('SELECT id FROM uses WHERE artifact_id = 10'));
        $this->assertSame(['beach-safe'], $this->column('SELECT tag FROM item_tags WHERE artifact_id = 10'));
        $this->assertSame(['survivor'], $this->column('SELECT note FROM event_items WHERE artifact_id = 10'));
        foreach (self::EXPECTED_ITEM_REFERENCES as [$table, $column]) {
            if ($column !== 'chosen_item_id') {
                $this->assertSame(['1'], $this->column("SELECT COUNT(*) FROM {$table} WHERE {$column} = 20"), "another owner's {$table}");
            }
        }
    }

    public function test_delete_clears_an_item_chosen_instead_link_but_keeps_the_name(): void
    {
        $this->items->delete(11);

        $proposal = $this->db->query('SELECT item_id, outcome, note, chosen_item_id, chosen_item_name FROM proposal_outcomes WHERE id = 2')->fetch_assoc();
        $this->assertSame(
            ['item_id' => '12', 'outcome' => 'chose_something_else', 'note' => 'Wanted tiles', 'chosen_item_id' => null, 'chosen_item_name' => 'Azul'],
            $proposal
        );
        $this->assertSame(['2'], $this->column('SELECT proposal_id FROM proposal_outcome_players WHERE player_id = 101'));
    }

    public function test_deleting_another_owners_item_changes_nothing(): void
    {
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->delete(20));
    }

    public function test_deleting_a_missing_item_changes_nothing(): void
    {
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->delete(999));
    }

    public function test_a_failed_delete_brings_every_dependent_back(): void
    {
        // The Item row's delete fails after everything pointing at it is
        // gone; the transaction must bring it all back.
        $this->runSql('CREATE TRIGGER games_no_delete BEFORE DELETE ON games FOR EACH ROW
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'no delete\'');

        $this->assertRefusedWithoutChange(\mysqli_sql_exception::class, fn () => $this->items->delete(11));
    }

    public function test_merge_moves_the_losers_history_to_the_survivor_and_deletes_the_loser(): void
    {
        $this->items->merge(10, 11);

        $this->assertSame([], $this->column('SELECT id FROM games WHERE id = 11'));
        $this->assertSame(['Catan'], $this->column('SELECT Title FROM games WHERE id = 10'), 'the survivor keeps its own fields');
        $this->assertSame(['1', '2', '3'], $this->column('SELECT id FROM uses WHERE artifact_id = 10 ORDER BY id'));
        $this->assertSame(['3'], $this->column('SELECT COUNT(*) FROM uses_players WHERE user_id = 1'), "the moved Uses keep their people");
        $this->assertSame(['1'], $this->column('SELECT COUNT(*) FROM responses WHERE Title = 10'));
        $this->assertSame(['3'], $this->column('SELECT SwS FROM sweetspots WHERE Title = 10'));
        $this->assertSame(['10'], $this->column('SELECT item_id FROM proposal_outcomes WHERE id = 1'));
        $this->assertSame(['10'], $this->column('SELECT chosen_item_id FROM proposal_outcomes WHERE id = 2'));
        $this->assertNothingPointsAt(11);
    }

    public function test_tags_and_ratings_the_survivor_already_has_are_not_duplicated(): void
    {
        $this->items->merge(10, 11);

        $this->assertSame(['beach-safe', 'family'], $this->column('SELECT tag FROM item_tags WHERE artifact_id = 10 ORDER BY tag'));
        $this->assertSame(
            ['Gyges 9.00', 'Other 7.00'],
            $this->column("SELECT CONCAT(bgg_username, ' ', rating) FROM item_bgg_ratings WHERE artifact_id = 10 ORDER BY bgg_username"),
            "the survivor's own rating wins"
        );
    }

    public function test_the_losers_event_plans_move_to_the_survivor_unless_it_is_already_planned(): void
    {
        $this->items->merge(10, 11);

        $this->assertSame(
            ['1 10 survivor', '2 10 loser', '3 20 theirs'],
            $this->column("SELECT CONCAT(event_id, ' ', artifact_id, ' ', note) FROM event_items ORDER BY event_id, artifact_id")
        );
    }

    public function test_a_proposal_where_the_loser_was_chosen_instead_of_the_survivor_loses_that_link(): void
    {
        $this->runSql("INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note, chosen_item_id) VALUES
            (3, 1, 10, '2026-04-03', 'chose_something_else', '', 11);");

        $this->items->merge(10, 11);

        $this->assertSame(['10'], $this->column('SELECT item_id FROM proposal_outcomes WHERE id = 3'));
        $this->assertSame([null], $this->column('SELECT chosen_item_id FROM proposal_outcomes WHERE id = 3'), 'an item is never chosen instead of itself');
    }

    public function test_merging_another_owners_item_either_way_changes_nothing(): void
    {
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->merge(10, 20));
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->merge(20, 10));
    }

    public function test_merging_a_missing_item_changes_nothing(): void
    {
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->merge(10, 999));
        $this->assertRefusedWithoutChange(\OutOfBoundsException::class, fn () => $this->items->merge(999, 11));
    }

    public function test_merging_an_item_into_itself_changes_nothing(): void
    {
        $this->assertRefusedWithoutChange(\InvalidArgumentException::class, fn () => $this->items->merge(10, 10));
    }

    public function test_a_failed_merge_changes_nothing(): void
    {
        $this->runSql('CREATE TRIGGER games_no_delete BEFORE DELETE ON games FOR EACH ROW
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'no delete\'');

        $this->assertRefusedWithoutChange(\mysqli_sql_exception::class, fn () => $this->items->merge(10, 11));
    }
}
