<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Merging one item into another moves every record that points at the loser
 * to the survivor, keeps the survivor's own fields, and deletes the loser.
 */
final class ItemMergeTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-proposal-outcomes.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-events.sql'));
        $this->runSql('CREATE TABLE sweetspots (id INT AUTO_INCREMENT PRIMARY KEY, Title INT NOT NULL, SwS VARCHAR(50) DEFAULT NULL) ENGINE=InnoDB;');
        require_once PRIVATE_PATH . '/item_merge.php';

        // Fixture items 10 (Catan) and 11 (Azul) belong to user 1, item 20 to user 2.
        $this->runSql("
            INSERT INTO uses (artifact_id, user_id, use_date) VALUES (11, 1, '2026-03-01'), (11, 1, '2026-03-02');
            INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2019-05-05');
            INSERT INTO sweetspots (Title, SwS) VALUES (11, '3');
            INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (1, 10, 'beach-safe'), (1, 11, 'beach-safe'), (1, 11, 'family');
            INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating) VALUES (1, 10, 'Gyges', 9.0), (1, 11, 'Gyges', 4.0), (1, 11, 'Other', 7.0);
            INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note, chosen_item_id) VALUES
                (1, 1, 11, '2026-04-01', 'explicit_decline', '', NULL),
                (2, 1, 12, '2026-04-02', 'chose_something_else', '', 11);
        ");
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

    private function column(string $sql): array
    {
        return array_map(fn ($row) => $row[0], $this->db->query($sql)->fetch_all());
    }

    public function test_merge_moves_the_losers_history_to_the_survivor_and_deletes_the_loser(): void
    {
        $this->assertTrue(merge_items($this->db, 10, 11, 1));

        $this->assertSame([], $this->column('SELECT id FROM games WHERE id = 11'));
        $this->assertSame(['Catan'], $this->column('SELECT Title FROM games WHERE id = 10'), "the survivor keeps its own fields");
        $this->assertSame(['3'], $this->column('SELECT COUNT(*) FROM uses WHERE artifact_id = 10'));
        $this->assertSame(['1'], $this->column('SELECT COUNT(*) FROM responses WHERE Title = 10'));
        $this->assertSame(['3'], $this->column('SELECT SwS FROM sweetspots WHERE Title = 10'));
        $this->assertSame(['10'], $this->column('SELECT item_id FROM proposal_outcomes WHERE id = 1'));
        $this->assertSame(['10'], $this->column('SELECT chosen_item_id FROM proposal_outcomes WHERE id = 2'));
        foreach (['uses' => 'artifact_id', 'responses' => 'Title', 'sweetspots' => 'Title', 'item_tags' => 'artifact_id', 'item_bgg_ratings' => 'artifact_id', 'proposal_outcomes' => 'item_id'] as $table => $col) {
            $this->assertSame(['0'], $this->column("SELECT COUNT(*) FROM {$table} WHERE {$col} = 11"), "{$table} still points at the loser");
        }
    }

    public function test_tags_and_ratings_the_survivor_already_has_are_not_duplicated(): void
    {
        merge_items($this->db, 10, 11, 1);

        $this->assertSame(['beach-safe', 'family'], $this->column('SELECT tag FROM item_tags WHERE artifact_id = 10 ORDER BY tag'));
        $this->assertSame(
            ['Gyges 9.00', 'Other 7.00'],
            $this->column("SELECT CONCAT(bgg_username, ' ', rating) FROM item_bgg_ratings WHERE artifact_id = 10 ORDER BY bgg_username"),
            "the survivor's own rating wins"
        );
    }

    public function test_the_losers_event_plans_move_to_the_survivor_unless_it_is_already_planned(): void
    {
        $this->runSql("INSERT INTO events (id, user_id, name) VALUES (1, 1, 'Beach week'), (2, 1, 'Game night');
            INSERT INTO event_items (event_id, artifact_id, note) VALUES (1, 10, 'survivor'), (1, 11, 'loser'), (2, 11, 'loser');");

        merge_items($this->db, 10, 11, 1);

        $this->assertSame(
            ['1 10 survivor', '2 10 loser'],
            $this->column("SELECT CONCAT(event_id, ' ', artifact_id, ' ', note) FROM event_items ORDER BY event_id, artifact_id")
        );
    }

    public function test_a_proposal_where_the_loser_was_chosen_instead_of_the_survivor_loses_that_link(): void
    {
        $this->runSql("INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note, chosen_item_id) VALUES
            (3, 1, 10, '2026-04-03', 'chose_something_else', '', 11);");

        merge_items($this->db, 10, 11, 1);

        $this->assertSame(['10'], $this->column('SELECT item_id FROM proposal_outcomes WHERE id = 3'));
        $this->assertSame([null], $this->column('SELECT chosen_item_id FROM proposal_outcomes WHERE id = 3'), 'an item is never chosen instead of itself');
    }

    public function test_merging_another_owners_item_changes_nothing(): void
    {
        $result = merge_items($this->db, 10, 20, 1);

        $this->assertSame(['Both items must belong to your account.'], $result);
        $this->assertSame(['20'], $this->column('SELECT id FROM games WHERE id = 20'));
        $this->assertSame(['2'], $this->column('SELECT COUNT(*) FROM uses WHERE artifact_id = 11'));
    }
}
