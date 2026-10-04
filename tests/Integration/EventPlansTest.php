<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: EventPlans, the owner's events and the items planned for each.
 * Events and their items never cross collections, and an event item carries
 * the event's own setting, note and packed mark alongside the item's facts.
 */
final class EventPlansTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-events.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-event-players.sql'));
        $this->runSql('ALTER TABLE players ADD COLUMN birth_year INT NULL');
        // The item columns in the case the schema gives them, as Items reads them.
        $this->runSql('ALTER TABLE games RENAME COLUMN mnp TO MnP, RENAME COLUMN mxp TO MxP, RENAME COLUMN ss TO SS,
            RENAME COLUMN mnt TO MnT, RENAME COLUMN mxt TO MxT');
        $this->runSql("UPDATE games SET mnp = 3, mxp = 4, ss = '3,4', Age = 10, mnt = 60, mxt = 120 WHERE id = 10");
        require_once PRIVATE_PATH . '/classes/EventPlans.php';
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

    private function plans(int $userId = 1): \EventPlans
    {
        return new \EventPlans($this->db, $userId);
    }

    public function test_owner_can_create_an_event_and_find_it(): void
    {
        $id = $this->plans()->save(['name' => ' Beach week ', 'starts_on' => '2027-07-03', 'ends_on' => '2027-07-10', 'notes' => 'Outer Banks']);

        $event = $this->plans()->find($id);
        $this->assertSame('Beach week', $event['name']);
        $this->assertSame('2027-07-03', $event['starts_on']);
        $this->assertSame('2027-07-10', $event['ends_on']);
        $this->assertSame('Outer Banks', $event['notes']);
        $this->assertSame([], $event['items']);
    }

    public function test_an_event_needs_a_name_and_dates_in_order(): void
    {
        foreach ([
            ['name' => '  '],
            ['name' => 'Beach', 'starts_on' => '2027-02-30'],
            ['name' => 'Beach', 'starts_on' => '2027-07-10', 'ends_on' => '2027-07-03'],
        ] as $input) {
            try {
                $this->plans()->save($input);
                $this->fail('Saved an invalid event: ' . json_encode($input));
            } catch (\InvalidArgumentException $error) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dates_are_optional(): void
    {
        $id = $this->plans()->save(['name' => 'Someday']);

        $this->assertNull($this->plans()->find($id)['starts_on']);
    }

    public function test_owner_can_rename_an_event(): void
    {
        $id = $this->plans()->save(['name' => 'Beach']);
        $this->plans()->save(['name' => 'Beach week'], $id);

        $this->assertSame('Beach week', $this->plans()->find($id)['name']);
    }

    public function test_added_items_come_back_with_their_facts_and_tags(): void
    {
        $this->db->query("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (1, 10, 'strategy')");
        $id = $this->plans()->save(['name' => 'Beach week']);

        $this->assertSame(2, $this->plans()->addItems($id, [10, 11]));

        $items = $this->plans()->find($id)['items'];
        $this->assertSame(['Azul', 'Catan'], array_column($items, 'Title'));
        $catan = $items[1];
        $this->assertSame(10, $catan['id']);
        $this->assertSame('3,4', $catan['SS']);
        $this->assertSame(3, $catan['MnP']);
        $this->assertSame(4, $catan['MxP']);
        $this->assertSame(10, $catan['Age']);
        $this->assertSame(60, $catan['MnT']);
        $this->assertSame(120, $catan['MxT']);
        $this->assertSame(['strategy'], $catan['tags']);
        $this->assertSame('', $catan['setting']);
        $this->assertFalse($catan['is_packed']);
    }

    public function test_adding_an_item_already_planned_keeps_its_details(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10]);
        $this->plans()->updateItem($id, 10, ['note' => 'requested by mom']);

        $this->assertSame(0, $this->plans()->addItems($id, [10]));
        $this->assertSame('requested by mom', $this->plans()->find($id)['items'][0]['note']);
    }

    public function test_another_users_items_cannot_be_added(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);

        $this->expectException(\InvalidArgumentException::class);
        $this->plans()->addItems($id, [10, 20]);
    }

    public function test_owner_can_set_an_items_setting_note_and_packed_mark(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10]);

        $this->plans()->updateItem($id, 10, ['setting' => ' beach ', 'note' => 'mom bringing']);
        $this->plans()->updateItem($id, 10, ['is_packed' => '1']);

        $item = $this->plans()->find($id)['items'][0];
        $this->assertSame('beach', $item['setting']);
        $this->assertSame('mom bringing', $item['note']);
        $this->assertTrue($item['is_packed']);
    }

    public function test_owner_can_remove_an_item_from_an_event(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10, 11]);

        $this->plans()->removeItem($id, 10);

        $this->assertSame(['Azul'], array_column($this->plans()->find($id)['items'], 'Title'));
    }

    public function test_the_list_counts_each_events_items_and_how_many_are_packed(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week', 'starts_on' => '2027-07-03']);
        $this->plans()->addItems($id, [10, 11, 12]);
        $this->plans()->updateItem($id, 11, ['is_packed' => '1']);
        $this->plans()->save(['name' => 'Game night']);

        $events = $this->plans()->all();

        $this->assertSame(['Beach week', 'Game night'], array_column($events, 'name'));
        $this->assertSame(3, $events[0]['item_count']);
        $this->assertSame(1, $events[0]['packed_count']);
        $this->assertSame(0, $events[1]['item_count']);
    }

    public function test_one_users_events_are_invisible_to_another(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);

        $this->assertNull($this->plans(2)->find($id));
        $this->assertSame([], $this->plans(2)->all());
        foreach ([
            fn() => $this->plans(2)->save(['name' => 'Mine now'], $id),
            fn() => $this->plans(2)->addItems($id, [20]),
            fn() => $this->plans(2)->delete($id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Changed another user\'s event.');
            } catch (\OutOfBoundsException $error) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('Beach week', $this->plans()->find($id)['name']);
    }

    public function test_deleting_an_event_deletes_its_plan(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10]);

        $this->plans()->delete($id);

        $this->assertNull($this->plans()->find($id));
        $this->assertSame([], $this->plans()->all());
    }

    public function test_items_to_add_are_all_the_owners_items_not_yet_planned_in_title_order(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [11]);

        $candidates = $this->plans()->itemsToAdd($id);

        // A game not kept, such as one being considered for purchase, can be planned too.
        $this->assertSame(['Arrival', 'Catan', 'Former possession'], array_column($candidates, 'Title'));
        $this->assertSame([false, true, false], array_column($candidates, 'is_kept'));
        $this->assertSame('3–4 players, best 3, 4 · Age 10+', $candidates[1]['facts']);
        $this->runSql("UPDATE types SET objectType = 'table game' WHERE id = 1");
        $this->assertSame([false, true, true], array_column($this->plans()->itemsToAdd($id), 'is_game'));
    }

    public function test_a_candidate_with_only_a_legacy_type_string_is_not_a_game(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->runSql("UPDATE games SET type_id = NULL, type = 'table game' WHERE id = 10");

        $candidates = array_column($this->plans()->itemsToAdd($id), 'is_game', 'Title');

        $this->assertFalse($candidates['Catan']);
    }

    public function test_a_planned_item_says_whether_it_is_kept(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10, 13]);

        $items = $this->plans()->find($id)['items'];

        $this->assertSame(['Catan', 'Former possession'], array_column($items, 'Title'));
        $this->assertSame([true, false], array_column($items, 'is_kept'));
    }

    public function test_owner_can_add_players_and_those_without_ages_come_back_in_name_order(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);

        $this->assertSame(2, $this->plans()->addPlayers($id, [100, '101']));

        $this->assertSame(
            [['id' => 101, 'name' => 'Jo Smith', 'age' => null], ['id' => 100, 'name' => 'Sam Lee', 'age' => null]],
            $this->plans()->find($id)['players']
        );
    }

    public function test_adding_a_player_already_coming_adds_nothing(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [100]);

        $this->assertSame(0, $this->plans()->addPlayers($id, [100]));
        $this->assertCount(1, $this->plans()->find($id)['players']);
    }

    public function test_another_users_players_cannot_be_added(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);

        $this->expectException(\InvalidArgumentException::class);
        $this->plans()->addPlayers($id, [100, 200]);
    }

    public function test_players_to_add_are_the_owners_players_not_yet_coming(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [101]);

        $this->assertSame([['id' => 100, 'name' => 'Sam Lee', 'age' => null]], $this->plans()->playersToAdd($id));
    }

    public function test_owner_can_remove_a_player_from_an_event(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [100, 101]);

        $this->plans()->removePlayer($id, 100);

        $this->assertSame(['Jo Smith'], array_column($this->plans()->find($id)['players'], 'name'));
    }

    public function test_the_list_counts_each_events_players(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addItems($id, [10, 11]);
        $this->plans()->addPlayers($id, [100, 101]);

        $event = $this->plans()->all()[0];

        $this->assertSame(2, $event['item_count']);
        $this->assertSame(2, $event['player_count']);
    }

    public function test_a_deleted_player_no_longer_counts_as_coming(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [100, 101]);

        $this->runSql('DELETE FROM players WHERE id = 100');

        $this->assertSame(['Jo Smith'], array_column($this->plans()->find($id)['players'], 'name'));
        $this->assertSame(1, $this->plans()->all()[0]['player_count']);
    }

    public function test_another_users_event_takes_no_players(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [100]);

        foreach ([
            fn() => $this->plans(2)->addPlayers($id, [200]),
            fn() => $this->plans(2)->removePlayer($id, 100),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Changed another user\'s event players.');
            } catch (\OutOfBoundsException $error) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(1, $this->plans()->find($id)['players']);
    }

    public function test_deleting_an_event_deletes_its_players(): void
    {
        $id = $this->plans()->save(['name' => 'Beach week']);
        $this->plans()->addPlayers($id, [100]);

        $this->plans()->delete($id);

        $this->assertSame('0', $this->db->query('SELECT COUNT(*) FROM event_players')->fetch_row()[0]);
    }

    public function test_a_players_age_is_their_age_in_the_year_the_event_starts(): void
    {
        $this->runSql('UPDATE players SET birth_year = 2015 WHERE id = 100');
        $dated = $this->plans()->save(['name' => 'Beach week', 'starts_on' => '2027-07-03']);
        $undated = $this->plans()->save(['name' => 'Someday']);
        $this->plans()->addPlayers($dated, [100, 101]);

        $this->assertSame([12, null], array_column($this->plans()->find($dated)['players'], 'age'));
        // An undated event counts from this year.
        $this->assertSame((int) date('Y') - 2015, $this->plans()->playersToAdd($undated)[1]['age']);
    }

    public function test_an_events_players_come_back_youngest_first_and_unknown_ages_last(): void
    {
        $this->runSql("INSERT INTO players (id, user_id, FirstName, LastName) VALUES (102, 1, 'Al', 'Young'), (103, 1, 'Bo', 'Twin');
            UPDATE players SET birth_year = 2015 WHERE id IN (100, 103);
            UPDATE players SET birth_year = 2020 WHERE id = 102");
        $id = $this->plans()->save(['name' => 'Beach week', 'starts_on' => '2027-07-03']);
        $this->plans()->addPlayers($id, [100, 101, 102, 103]);

        $this->assertSame(
            ['Al Young', 'Bo Twin', 'Sam Lee', 'Jo Smith'],
            array_column($this->plans()->find($id)['players'], 'name')
        );
    }
}
