<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Uses;

final class UsesTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Uses $uses;

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
        $this->db->query('CREATE TABLE uses_players (
            id INT PRIMARY KEY AUTO_INCREMENT,
            use_id INT NOT NULL,
            player_id INT NOT NULL,
            user_id INT NOT NULL,
            UNIQUE KEY use_player (use_id, player_id)
        ) ENGINE=InnoDB');
        require_once PRIVATE_PATH . '/classes/Uses.php';
        $this->uses = new Uses($this->db, 1);
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

    private function use(array $changes = []): array
    {
        return array_replace([
            'item_id' => 10,
            'use_date' => '2026-09-12',
            'setting' => 'Kitchen table',
            'notes' => 'Close game',
            'player_ids' => [100, 101],
        ], $changes);
    }

    private function rowCounts(): array
    {
        return [
            'uses' => (int) $this->db->query('SELECT COUNT(*) FROM uses')->fetch_row()[0],
            'uses_players' => (int) $this->db->query('SELECT COUNT(*) FROM uses_players')->fetch_row()[0],
        ];
    }

    public function test_recording_three_uses_returns_three_use_ids_with_the_same_people(): void
    {
        $ids = $this->uses->record($this->use(['count' => 3]));

        $this->assertCount(3, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            $use = $this->uses->find($id);
            $this->assertSame($id, $use['id']);
            $this->assertSame(10, $use['item_id']);
            $this->assertSame('Catan', $use['item_title']);
            $this->assertSame('2026-09-12', $use['use_date']);
            $this->assertSame('Kitchen table', $use['setting']);
            $this->assertSame('Close game', $use['notes']);
            $this->assertSame([['id' => 100, 'name' => 'Sam Lee', 'first_name' => 'Sam', 'last_name' => 'Lee'], ['id' => 101, 'name' => 'Jo Smith', 'first_name' => 'Jo', 'last_name' => 'Smith']], $use['people']);
        }
    }

    public function test_the_returned_ids_are_uses_rows_in_the_order_written(): void
    {
        $ids = $this->uses->record($this->use(['count' => 2]));

        $written = array_map('intval', array_column(
            $this->db->query('SELECT id FROM uses WHERE use_date = \'2026-09-12\' ORDER BY id')->fetch_all(MYSQLI_ASSOC),
            'id'
        ));
        $this->assertSame($written, $ids);
    }

    public function test_the_count_is_clamped_and_defaults_to_one(): void
    {
        $this->assertCount(1, $this->uses->record($this->use()));
        $this->assertCount(1, $this->uses->record($this->use(['count' => 0])));
        $this->assertCount(20, $this->uses->record($this->use(['count' => 500])));
    }

    public function test_repeated_and_zero_people_collapse_to_one_each(): void
    {
        [$id] = $this->uses->record($this->use(['player_ids' => [100, 0, '100', 101, 100]]));

        $this->assertSame([100, 101], array_column($this->uses->find($id)['people'], 'id'));
    }

    public function test_people_are_listed_in_the_order_they_were_saved(): void
    {
        [$id] = $this->uses->record($this->use(['player_ids' => [101, 100]]));

        $this->assertSame([101, 100], array_column($this->uses->find($id)['people'], 'id'));
    }

    public function test_a_use_can_be_recorded_alone(): void
    {
        [$id] = $this->uses->record($this->use(['player_ids' => [], 'setting' => '', 'notes' => '']));

        $use = $this->uses->find($id);
        $this->assertSame([], $use['people']);
        $this->assertSame('', $use['setting']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedInput')]
    public function test_bad_input_is_rejected_and_nothing_is_written(array $changes): void
    {
        $before = $this->rowCounts();
        try {
            $this->uses->record($this->use($changes + ['count' => 3]));
            $this->fail('The input must be rejected.');
        } catch (\InvalidArgumentException $expected) {
            $this->assertSame($before, $this->rowCounts());
        }
    }

    public function test_another_users_item_or_person_is_refused_in_the_owners_words(): void
    {
        foreach ([
            'Choose an item from your own items.' => ['item_id' => 20],
            'Choose people from your own people list.' => ['player_ids' => [100, 200]],
        ] as $message => $changes) {
            try {
                $this->uses->record($this->use($changes));
                $this->fail('The input must be rejected.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }

    public static function rejectedInput(): array
    {
        return [
            'another user\'s item' => [['item_id' => 20]],
            'another user\'s person' => [['player_ids' => [100, 200]]],
            'missing person' => [['player_ids' => [999]]],
            'malformed person' => [['player_ids' => [['1']]]],
            'missing item' => [['item_id' => 999]],
            'no item' => [['item_id' => '']],
            'impossible date' => [['use_date' => '2026-02-30']],
            'zero date' => [['use_date' => '0000-00-00']],
            'missing date' => [['use_date' => '']],
            'date with time' => [['use_date' => '2026-09-12 12:00:00']],
        ];
    }

    public function test_no_item_chosen_asks_for_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please choose an item.');
        $this->uses->record($this->use(['item_id' => '']));
    }

    public function test_update_replaces_the_item_date_setting_notes_and_people(): void
    {
        [$id] = $this->uses->record($this->use());

        $this->uses->update($id, [
            'item_id' => 11,
            'use_date' => '2026-09-01',
            'setting' => 'Cabin',
            'notes' => 'Rematch',
            'player_ids' => [101],
        ]);

        $use = $this->uses->find($id);
        $this->assertSame(11, $use['item_id']);
        $this->assertSame('Azul', $use['item_title']);
        $this->assertSame('2026-09-01', $use['use_date']);
        $this->assertSame('Cabin', $use['setting']);
        $this->assertSame('Rematch', $use['notes']);
        $this->assertSame([['id' => 101, 'name' => 'Jo Smith', 'first_name' => 'Jo', 'last_name' => 'Smith']], $use['people']);
    }

    public function test_a_rejected_update_leaves_the_use_alone(): void
    {
        [$id] = $this->uses->record($this->use());
        $before = $this->uses->find($id);

        foreach ([['item_id' => 20], ['player_ids' => [200]], ['use_date' => 'yesterday']] as $changes) {
            try {
                $this->uses->update($id, $this->use($changes));
                $this->fail('The update must be rejected.');
            } catch (\InvalidArgumentException $expected) {
                $this->assertSame($before, $this->uses->find($id));
            }
        }
    }

    public function test_delete_removes_the_use_and_its_people(): void
    {
        [$kept] = $this->uses->record($this->use());
        [$id] = $this->uses->record($this->use());

        $this->uses->delete($id);

        $this->assertNull($this->uses->find($id));
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM uses_players WHERE use_id = $id")->fetch_row()[0]);
        $this->assertCount(2, $this->uses->find($kept)['people']);
    }

    public function test_another_users_use_reads_as_absent_and_cannot_be_changed(): void
    {
        [$theirs] = (new Uses($this->db, 2))->record([
            'item_id' => 20,
            'use_date' => '2026-09-12',
            'player_ids' => [200],
        ]);
        $before = $this->rowCounts();

        $this->assertNull($this->uses->find($theirs));
        foreach (['update', 'delete'] as $action) {
            try {
                if ($action === 'update') {
                    $this->uses->update($theirs, $this->use());
                } else {
                    $this->uses->delete($theirs);
                }
                $this->fail('Another user must not ' . $action . ' this use.');
            } catch (\OutOfBoundsException $expected) {
                $this->assertSame($before, $this->rowCounts());
                $use = (new Uses($this->db, 2))->find($theirs);
                $this->assertSame(20, $use['item_id']);
                $this->assertSame([200], array_column($use['people'], 'id'));
            }
        }
    }

    public function test_a_missing_use_reads_as_absent(): void
    {
        $this->assertNull($this->uses->find(999));
        $this->expectException(\OutOfBoundsException::class);
        $this->uses->delete(999);
    }

    public function test_all_lists_the_owners_uses_newest_first_each_as_find_reads_it(): void
    {
        $first = $this->db->query('SELECT id FROM uses')->fetch_row()[0];
        [$older] = $this->uses->record($this->use(['use_date' => '2026-03-01', 'player_ids' => [101, 100]]));
        [$sameDay, $newest] = $this->uses->record($this->use(['count' => 2]));
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-12-01', 'player_ids' => [200]]);

        $all = $this->uses->all();

        $this->assertSame([$newest, $sameDay, $older, (int) $first], array_column($all, 'id'));
        foreach ($all as $use) {
            $this->assertSame($this->uses->find($use['id']), $use);
        }
        $this->assertSame([101, 100], array_column($all[2]['people'], 'id'));
    }

    public function test_all_filters_by_item_and_by_person(): void
    {
        [$catanWithJo] = $this->uses->record($this->use(['player_ids' => [101]]));
        [$azulWithJo] = $this->uses->record($this->use(['item_id' => 11, 'use_date' => '2026-09-13', 'player_ids' => [101]]));
        [$catanWithSam] = $this->uses->record($this->use(['use_date' => '2026-09-14', 'player_ids' => [100]]));

        $this->assertSame([$azulWithJo, $catanWithJo], array_column($this->uses->all(['person_id' => 101]), 'id'));
        $this->assertSame([$catanWithJo], array_column($this->uses->all(['item_id' => 10, 'person_id' => 101]), 'id'));
        $this->assertSame([$azulWithJo], array_column($this->uses->all(['item_id' => 11]), 'id'));
        $this->assertContains($catanWithSam, array_column($this->uses->all(['item_id' => 10]), 'id'));
    }

    public function test_all_matches_nothing_for_another_owners_item_or_person(): void
    {
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12', 'player_ids' => [200]]);
        $this->uses->record($this->use());

        $this->assertSame([], $this->uses->all(['item_id' => 20]));
        $this->assertSame([], $this->uses->all(['person_id' => 200]));
    }

    public function test_all_filters_by_item_type(): void
    {
        [$catan] = $this->uses->record($this->use());
        [$arrival] = $this->uses->record($this->use(['item_id' => 12, 'use_date' => '2026-09-13']));

        $this->assertSame([$arrival], array_column($this->uses->all(['type_ids' => [2]]), 'id'));
        $this->assertContains($catan, array_column($this->uses->all(['type_ids' => [1]]), 'id'));
        $this->assertNotContains($arrival, array_column($this->uses->all(['type_ids' => [1]]), 'id'));
        $this->assertCount(3, $this->uses->all(['type_ids' => ['1', '2']]));
        $this->assertCount(3, $this->uses->all(['type_ids' => null]));
    }

    public function test_no_type_ids_lists_nothing(): void
    {
        $this->uses->record($this->use());

        $this->assertSame([], $this->uses->all(['type_ids' => []]));
    }

    public function test_all_keeps_uses_on_or_after_the_since_date(): void
    {
        [$before] = $this->uses->record($this->use(['use_date' => '2026-09-11']));
        [$onTheDay] = $this->uses->record($this->use(['use_date' => '2026-09-12']));
        [$after] = $this->uses->record($this->use(['use_date' => '2026-09-13']));

        $this->assertSame([$after, $onTheDay], array_column($this->uses->all(['since' => '2026-09-12']), 'id'));
        $this->assertContains($before, array_column($this->uses->all(['since' => '']), 'id'));
        $this->assertContains($before, array_column($this->uses->all(['since' => '  ']), 'id'));
        $this->assertCount(4, $this->uses->all(['since' => null]));
    }

    public function test_uses_with_no_date_stay_in_the_unfiltered_listing(): void
    {
        $this->db->query('INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, NULL)');
        $undated = (int) $this->db->insert_id;

        $this->assertContains($undated, array_column($this->uses->all(), 'id'));
        $this->assertNotContains($undated, array_column($this->uses->all(['since' => '2000-01-01']), 'id'));
    }

    public static function malformedSince(): array
    {
        return [
            'not a date' => ['soon'],
            'impossible date' => ['2026-02-30'],
            'US order' => ['09/12/2026'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSince')]
    public function test_a_malformed_since_date_is_refused(string $since): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter a valid date in YYYY-MM-DD format.');
        $this->uses->all(['since' => $since]);
    }

    public function test_an_unknown_filter_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uses->all(['type' => [1]]);
    }

    public function test_filters_combine(): void
    {
        [$catanWithJo] = $this->uses->record($this->use(['player_ids' => [101]]));
        $this->uses->record($this->use(['use_date' => '2026-01-01', 'player_ids' => [101]]));
        $this->uses->record($this->use(['item_id' => 12, 'player_ids' => [101]]));

        $this->assertSame([$catanWithJo], array_column($this->uses->all([
            'person_id' => 101, 'type_ids' => [1], 'since' => '2026-09-01',
        ]), 'id'));
    }

    public function test_each_use_carries_its_items_type_name(): void
    {
        [$catan] = $this->uses->record($this->use());
        [$arrival] = $this->uses->record($this->use(['item_id' => 12]));
        $this->db->query('UPDATE games SET type_id = NULL WHERE id = 11');
        [$azul] = $this->uses->record($this->use(['item_id' => 11]));

        $this->assertSame('board-game', $this->uses->find($catan)['item_type']);
        $this->assertSame('film', $this->uses->find($arrival)['item_type']);
        $this->assertNull($this->uses->find($azul)['item_type']);
        $this->assertSame('board-game', array_column($this->uses->all(), 'item_type', 'id')[$catan]);
    }

    public function test_another_owners_item_and_people_do_not_leak_into_the_listing(): void
    {
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date, note) VALUES (20, 1, '2026-09-20', 'Their place')");
        $crossed = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO uses_players (use_id, player_id, user_id) VALUES ($crossed, 200, 1)");

        $use = array_column($this->uses->all(), null, 'id')[$crossed];
        $this->assertSame('', $use['item_title']);
        $this->assertNull($use['item_type']);
        $this->assertSame([], $use['people']);
        $this->assertSame([], $this->uses->all(['type_ids' => [1, 2], 'since' => '2026-09-20']));
    }

    public function test_the_last_setting_is_the_most_recently_recorded_uses(): void
    {
        $this->uses->record($this->use(['use_date' => '2026-12-31', 'setting' => 'Cabin']));
        $this->uses->record($this->use(['use_date' => '2026-01-01', 'setting' => 'Kitchen table']));
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12', 'setting' => 'Their place']);

        $this->assertSame('Kitchen table', $this->uses->lastSetting());
    }

    public function test_the_last_setting_is_null_without_a_use_or_a_stored_setting(): void
    {
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12', 'setting' => 'Their place']);
        $this->assertNull((new Uses($this->db, 3))->lastSetting());

        $this->uses->record($this->use(['setting' => 'Kitchen table']));
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date, note) VALUES (10, 1, '2026-01-01', NULL)");
        $this->assertNull($this->uses->lastSetting());
    }

    public function test_an_empty_last_setting_is_kept(): void
    {
        $this->uses->record($this->use(['setting' => '']));

        $this->assertSame('', $this->uses->lastSetting());
    }

    /** Give the legacy tables the columns Use counts read, and mark person 100 as owner 1. */
    private function legacyPlays(): void
    {
        $this->runSql('ALTER TABLE responses ADD COLUMN Player INT, ADD COLUMN AversionDate DATE;
            ALTER TABLE players ADD COLUMN represents_user_id INT DEFAULT NULL;
            UPDATE users SET player_id = 100 WHERE id = 1;
            UPDATE users SET player_id = 200 WHERE id = 2;');
    }

    private function play(int $itemId, string $date, int $personId = 100, int $ownerId = 1): void
    {
        $stmt = $this->db->prepare('INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiis', $itemId, $ownerId, $personId, $date);
        $stmt->execute();
        $stmt->close();
    }

    public function test_the_use_count_adds_uses_and_the_owners_legacy_plays(): void
    {
        $this->legacyPlays();
        $this->uses->record($this->use(['item_id' => 11, 'count' => 2]));
        $this->play(11, '2020-05-01');
        $this->play(12, '2021-06-01');

        $this->assertSame([
            ['item_id' => 11, 'item_title' => 'Azul', 'item_type' => 'board-game', 'use_count' => 3],
            ['item_id' => 12, 'item_title' => 'Arrival', 'item_type' => 'film', 'use_count' => 1],
            ['item_id' => 10, 'item_title' => 'Catan', 'item_type' => 'board-game', 'use_count' => 1],
        ], $this->uses->useCounts());
    }

    public function test_another_owners_uses_and_legacy_plays_never_count(): void
    {
        $this->legacyPlays();
        (new Uses($this->db, 2))->record(['item_id' => 20, 'use_date' => '2026-09-12']);
        $this->db->query("INSERT INTO uses (artifact_id, user_id, use_date) VALUES (11, 2, '2026-09-12')");
        $this->play(20, '2026-09-12', 200, 2);
        $this->play(11, '2026-09-12', 100, 2);
        $this->play(20, '2026-09-12', 100, 1);

        $this->assertSame([10 => 1], array_column($this->uses->useCounts(), 'use_count', 'item_id'));
        $this->assertSame([20 => 2], array_column((new Uses($this->db, 2))->useCounts(), 'use_count', 'item_id'));
    }

    public function test_legacy_plays_by_anyone_but_the_owners_own_person_do_not_count(): void
    {
        $this->legacyPlays();
        $this->play(11, '2026-09-12', 101);
        $this->play(11, '2026-09-12', 100);

        $this->assertSame([11 => 1, 10 => 1], array_column($this->uses->useCounts(), 'use_count', 'item_id'));
    }

    public function test_an_aversion_only_row_does_not_count(): void
    {
        $this->legacyPlays();
        $this->db->query("INSERT INTO responses (Title, user_id, Player, AversionDate) VALUES (11, 1, 100, '2026-09-12')");
        $this->db->query("INSERT INTO responses (Title, user_id, Player, PlayDate, AversionDate) VALUES (12, 1, 100, '2026-09-10', '2026-09-12')");

        $this->assertSame([12 => 1, 10 => 1], array_column($this->uses->useCounts(), 'use_count', 'item_id'));
        $this->assertSame([], $this->uses->useCounts('2026-09-11'));
    }

    public function test_the_since_date_is_inclusive_for_uses_and_legacy_plays(): void
    {
        $this->legacyPlays();
        $this->uses->record($this->use(['item_id' => 11, 'use_date' => '2026-09-12']));
        $this->uses->record($this->use(['item_id' => 11, 'use_date' => '2026-09-11']));
        $this->play(12, '2026-09-12');
        $this->play(12, '2026-09-11');

        $this->assertSame([
            ['item_id' => 12, 'item_title' => 'Arrival', 'item_type' => 'film', 'use_count' => 1],
            ['item_id' => 11, 'item_title' => 'Azul', 'item_type' => 'board-game', 'use_count' => 1],
        ], $this->uses->useCounts('2026-09-12'));
    }

    public function test_an_owner_with_no_person_marked_as_themself_still_gets_their_uses_counted(): void
    {
        $this->legacyPlays();
        $this->db->query('UPDATE users SET player_id = NULL WHERE id = 1');
        $this->play(11, '2026-09-12', 100);
        $this->play(12, '2026-09-12', 0);

        $this->assertSame([10 => 1], array_column($this->uses->useCounts(), 'use_count', 'item_id'));
    }

    public function test_the_owners_person_is_found_as_people_finds_them(): void
    {
        $this->legacyPlays();
        $this->db->query('UPDATE users SET player_id = NULL WHERE id = 1');
        $this->db->query('UPDATE players SET represents_user_id = 1 WHERE id = 101');
        $this->play(11, '2026-09-12', 101);

        $this->assertSame([11 => 1, 10 => 1], array_column($this->uses->useCounts(), 'use_count', 'item_id'));
    }

    public function test_a_since_date_that_is_not_a_calendar_date_throws(): void
    {
        $this->legacyPlays();
        foreach (['2026-02-30', 'last year', ''] as $since) {
            try {
                $this->uses->useCounts($since);
                $this->fail("Expected InvalidArgumentException for '$since'.");
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('Enter a valid date in YYYY-MM-DD format.', $error->getMessage());
            }
        }
    }
}
