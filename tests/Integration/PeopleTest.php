<?php

namespace Tests\Integration;

use People;
use PHPUnit\Framework\TestCase;

final class PeopleTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private People $people;

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
        $this->runSql('ALTER TABLE players
            MODIFY id INT AUTO_INCREMENT,
            ADD COLUMN FullName VARCHAR(255) DEFAULT NULL,
            ADD COLUMN G VARCHAR(10) DEFAULT NULL,
            ADD COLUMN birth_year INT DEFAULT NULL,
            ADD COLUMN represents_user_id INT DEFAULT NULL');
        $this->runSql('CREATE TABLE uses_players (
            id INT PRIMARY KEY AUTO_INCREMENT,
            use_id INT NOT NULL,
            player_id INT NOT NULL,
            user_id INT NOT NULL,
            UNIQUE KEY use_player (use_id, player_id)
        ) ENGINE=InnoDB');
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-proposal-outcomes.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-events.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-event-players.sql'));
        $this->runSql('CREATE TABLE playgroup (
            ID INT PRIMARY KEY AUTO_INCREMENT,
            FullName INT NOT NULL,
            user_id INT NOT NULL
        ) ENGINE=InnoDB');
        require_once PRIVATE_PATH . '/classes/People.php';
        $this->people = new People($this->db, 1);
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

    public function test_all_returns_only_the_owners_people_in_name_order(): void
    {
        $this->db->query("INSERT INTO players (id, user_id, FirstName, LastName) VALUES (102, 1, 'Jo', 'Adams'), (103, 1, 'Jo', 'Adams')");

        $this->assertSame([102, 103, 101, 100], array_column($this->people->all(), 'id'));
    }

    public function test_a_created_person_is_found_with_domain_keys(): void
    {
        $id = $this->people->create(['first_name' => '  Ada ', 'last_name' => ' Lovelace', 'gender' => 'F', 'birth_year' => '1990']);

        $this->assertSame([
            'id' => $id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'name' => 'Ada Lovelace',
            'gender' => 'F',
            'birth_year' => 1990,
            'is_me' => false,
        ], $this->people->find($id));
        $this->assertSame('Ada Lovelace', $this->fullName($id));
    }

    public function test_another_owners_person_reads_as_absent(): void
    {
        $this->assertNull($this->people->find(200));
        $this->assertNull($this->people->find(999));
        $this->assertSame('Sam Lee', $this->people->find(100)['name']);
    }

    public function test_search_finds_only_the_owners_people(): void
    {
        $this->assertSame([], $this->people->search('Other'));
        $this->assertSame([200], array_column((new People($this->db, 2))->search('Other'), 'id'));
    }

    public function test_search_matches_part_of_a_first_last_or_whole_name_in_name_order(): void
    {
        $this->db->query("INSERT INTO players (id, user_id, FirstName, LastName) VALUES (102, 1, 'Ada', 'Lee')");

        $this->assertSame([100], array_column($this->people->search('am'), 'id'));
        $this->assertSame([101], array_column($this->people->search('SMI'), 'id'));
        $this->assertSame([102, 100], array_column($this->people->search('lee'), 'id'));
        $this->assertSame([101], array_column($this->people->search(' jo smith '), 'id'));
        $this->assertSame($this->people->find(100), $this->people->search('Sam L')[0]);
    }

    public function test_search_finds_a_renamed_person_by_the_new_name(): void
    {
        $this->people->update(100, ['first_name' => 'Samuel', 'last_name' => 'Park']);

        $this->assertSame([100], array_column($this->people->search('Park'), 'id'));
        $this->assertSame([], $this->people->search('Lee'));
    }

    public function test_a_blank_search_returns_all_the_owners_people(): void
    {
        $this->assertSame($this->people->all(), $this->people->search(''));
        $this->assertSame($this->people->all(), $this->people->search('   '));
    }

    public function test_search_matches_like_wildcards_literally(): void
    {
        $this->db->query("INSERT INTO players (id, user_id, FirstName, LastName) VALUES (102, 1, '100%', 'Fan')");

        $this->assertSame([102], array_column($this->people->search('%'), 'id'));
        $this->assertSame([], $this->people->search('_'));
        $this->assertSame([], $this->people->search('S%e'));
    }

    public function test_create_defaults_gender_and_blank_birth_year_and_keeps_a_single_name(): void
    {
        $id = $this->people->create(['first_name' => '', 'last_name' => 'Cher', 'gender' => ' ', 'birth_year' => '']);

        $person = $this->people->find($id);
        $this->assertSame('Cher', $person['name']);
        $this->assertSame('other', $person['gender']);
        $this->assertNull($person['birth_year']);
    }

    public function test_create_refuses_a_person_with_no_name_and_writes_nothing(): void
    {
        $before = count($this->people->all());
        try {
            $this->people->create(['first_name' => '  ', 'last_name' => '', 'gender' => 'F', 'birth_year' => '']);
            $this->fail('A person with no name was created.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Please enter a name.', $error->getMessage());
        }
        $this->assertCount($before, $this->people->all());
    }

    public static function badBirthYears(): array
    {
        $nextYear = (int) date('Y') + 1;
        return [
            'too early' => ['1899'],
            'after next year' => [(string) ($nextYear + 1)],
            'not a number' => ['abc'],
            'a fraction' => ['1990.5'],
            'an array' => [['1990']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBirthYears')]
    public function test_create_refuses_a_birth_year_that_isnt_real(mixed $birthYear): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter a real birth year.');

        $this->people->create(['first_name' => 'Ada', 'last_name' => '', 'gender' => '', 'birth_year' => $birthYear]);
    }

    public function test_create_accepts_the_birth_year_bounds(): void
    {
        $nextYear = (int) date('Y') + 1;
        $this->assertSame(1900, $this->people->find($this->people->create(['first_name' => 'Old', 'birth_year' => '1900']))['birth_year']);
        $this->assertSame($nextYear, $this->people->find($this->people->create(['first_name' => 'New', 'birth_year' => $nextYear]))['birth_year']);
    }

    public function test_a_rename_updates_the_name_and_full_name(): void
    {
        $this->people->update(100, ['first_name' => 'Samuel', 'last_name' => 'Lee-Park', 'gender' => 'M', 'birth_year' => '1985', 'is_me' => false]);

        $person = $this->people->find(100);
        $this->assertSame('Samuel Lee-Park', $person['name']);
        $this->assertSame('M', $person['gender']);
        $this->assertSame(1985, $person['birth_year']);
        $this->assertSame('Samuel Lee-Park', $this->fullName(100));
    }

    public function test_update_refuses_bad_input_and_writes_nothing(): void
    {
        try {
            $this->people->update(100, ['first_name' => '', 'last_name' => '', 'is_me' => true]);
            $this->fail('A person was saved with no name.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Please enter a name.', $error->getMessage());
        }
        $this->assertSame('Sam Lee', $this->people->find(100)['name']);
        $this->assertNull($this->accountLink());
    }

    public function test_ticking_me_marks_exactly_one_person_and_links_the_account(): void
    {
        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => true]);
        $this->assertTrue($this->people->find(100)['is_me']);
        $this->assertSame(100, $this->accountLink());

        $this->people->update(101, ['first_name' => 'Jo', 'last_name' => 'Smith', 'is_me' => true]);

        $this->assertFalse($this->people->find(100)['is_me']);
        $this->assertTrue($this->people->find(101)['is_me']);
        $this->assertSame([101], array_column(array_filter($this->people->all(), fn ($person) => $person['is_me']), 'id'));
        $this->assertSame(101, $this->accountLink());
    }

    public function test_unticking_me_on_someone_else_leaves_the_mark_and_link(): void
    {
        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => true]);

        $this->people->update(101, ['first_name' => 'Jo', 'last_name' => 'Smith', 'is_me' => false]);

        $this->assertTrue($this->people->find(100)['is_me']);
        $this->assertSame(100, $this->accountLink());
    }

    public function test_unticking_me_on_the_person_who_is_me_removes_the_mark_and_link(): void
    {
        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => true]);

        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => false]);

        $this->assertFalse($this->people->find(100)['is_me']);
        $this->assertNull($this->accountLink());
    }

    public function test_me_is_the_person_the_account_links_to(): void
    {
        $this->people->update(101, ['first_name' => 'Jo', 'last_name' => 'Smith', 'is_me' => true]);

        $this->assertSame(101, $this->people->me());
        $this->assertSame(101, $this->accountLink());
    }

    public function test_me_repairs_a_missing_link_to_the_person_who_represents_the_account(): void
    {
        $this->db->query('UPDATE players SET represents_user_id = 1 WHERE id = 101');

        $this->assertSame(101, $this->people->me());
        $this->assertSame(101, $this->accountLink());
    }

    public function test_me_is_null_with_no_link_and_no_person_who_represents_the_account(): void
    {
        $this->db->query('UPDATE players SET represents_user_id = 1 WHERE id = 200');

        $this->assertNull($this->people->me());
        $this->assertNull($this->accountLink());
    }

    public function test_another_owners_person_cannot_be_updated(): void
    {
        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => true]);
        try {
            $this->people->update(200, ['first_name' => 'Taken', 'last_name' => 'Over', 'is_me' => true]);
            $this->fail('Another owner\'s person was updated.');
        } catch (\OutOfBoundsException) {
        }

        $other = $this->db->query('SELECT FirstName, represents_user_id FROM players WHERE id = 200')->fetch_assoc();
        $this->assertSame(['FirstName' => 'Other', 'represents_user_id' => null], $other);
        $this->assertTrue($this->people->find(100)['is_me']);
        $this->assertSame(100, $this->accountLink());
    }

    public function test_delete_removes_the_persons_links_and_then_the_person(): void
    {
        $this->seedLinks();

        $this->people->delete(101);

        $this->assertNull($this->people->find(101));
        $this->assertSame(['uses' => 0, 'proposals' => 0, 'events' => 0, 'playgroup' => 0], $this->links(101));
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(100));
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(200));
    }

    public function test_deleting_the_person_who_is_me_clears_the_account_link(): void
    {
        $this->people->update(100, ['first_name' => 'Sam', 'last_name' => 'Lee', 'is_me' => true]);

        $this->people->delete(101);
        $this->assertSame(100, $this->accountLink());

        $this->people->delete(100);
        $this->assertNull($this->accountLink());
    }

    public function test_another_owners_person_cannot_be_deleted(): void
    {
        $this->seedLinks();
        $this->db->query('UPDATE users SET player_id = 200 WHERE id = 2');

        try {
            $this->people->delete(200);
            $this->fail('Another owner\'s person was deleted.');
        } catch (\OutOfBoundsException) {
        }

        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM players WHERE id = 200')->fetch_row()[0]);
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(200));
        $this->assertSame(200, $this->accountLink(2));
    }

    public function test_a_delete_that_fails_partway_leaves_the_person_and_their_links(): void
    {
        $this->seedLinks();
        $this->db->query('RENAME TABLE playgroup TO playgroup_away');

        try {
            $this->people->delete(101);
            $this->fail('The delete did not fail.');
        } catch (\mysqli_sql_exception) {
        }
        $this->db->query('RENAME TABLE playgroup_away TO playgroup');

        $this->assertSame('Jo Smith', $this->people->find(101)['name']);
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(101));
    }

    public function test_merge_drops_shared_links_repoints_the_rest_and_deletes_the_merged_person(): void
    {
        $this->seedLinks();
        $this->runSql("
            INSERT INTO uses_players (use_id, player_id, user_id) VALUES (3, 101, 1);
            INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note) VALUES
                (3, 1, 10, '2026-03-02', 'explicit_decline', '');
            INSERT INTO proposal_outcome_players VALUES (3, 101);
            INSERT INTO event_players VALUES (2, 101);
        ");

        $this->people->merge(100, 101);

        $this->assertNull($this->people->find(101));
        $this->assertSame('Sam Lee', $this->people->find(100)['name']);
        $this->assertSame(['uses' => 0, 'proposals' => 0, 'events' => 1, 'playgroup' => 0], $this->links(101));
        $this->assertSame(['uses' => 2, 'proposals' => 2, 'events' => 1, 'playgroup' => 2], $this->links(100));
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(200));
    }

    public function test_merge_moves_the_merged_persons_events_to_the_survivor(): void
    {
        $this->runSql("INSERT INTO events (id, user_id, name) VALUES (1, 1, 'Beach week'), (2, 1, 'Game night');
            INSERT INTO event_players (event_id, player_id) VALUES (1, 100), (1, 101), (2, 101)");

        $this->people->merge(100, 101);

        $rows = $this->db->query('SELECT event_id, player_id FROM event_players ORDER BY event_id')->fetch_all();
        $this->assertSame([['1', '100'], ['2', '100']], $rows);
    }

    public static function refusedMerges(): array
    {
        return [
            'another owner\'s merged person' => [100, 200, 'Both players must exist.'],
            'another owner\'s survivor' => [200, 100, 'Both players must exist.'],
            'a missing person' => [100, 999, 'Both players must exist.'],
            'the same person' => [100, 100, 'Cannot merge a player into itself.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedMerges')]
    public function test_merge_refuses_and_changes_nothing(int $survivorId, int $loserId, string $message): void
    {
        $this->seedLinks();

        $this->assertMergeRefused($survivorId, $loserId, $message);

        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM players WHERE id = 200')->fetch_row()[0]);
        foreach ([100, 101, 200] as $playerId) {
            $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links($playerId));
        }
    }

    public function test_merge_keeps_the_person_who_is_me_as_the_survivor(): void
    {
        $this->people->update(101, ['first_name' => 'Jo', 'last_name' => 'Smith', 'is_me' => true]);

        $this->assertMergeRefused(100, 101, 'The surviving player must be the one marked as you.');

        $this->people->merge(101, 100);
        $this->assertNull($this->people->find(100));
        $this->assertTrue($this->people->find(101)['is_me']);
        $this->assertSame(101, $this->accountLink());
    }

    public function test_merge_refuses_to_delete_the_person_the_account_links_to(): void
    {
        $this->db->query('UPDATE users SET player_id = 101 WHERE id = 1');

        $this->assertMergeRefused(100, 101, 'The surviving player must be the one marked as you.');
        $this->assertSame(101, $this->accountLink());
    }

    public function test_a_merge_that_fails_partway_leaves_both_people_and_their_links(): void
    {
        $this->seedLinks();
        $this->db->query('RENAME TABLE event_players TO event_players_away');

        try {
            $this->people->merge(100, 101);
            $this->fail('The merge did not fail.');
        } catch (\mysqli_sql_exception) {
        }
        $this->db->query('RENAME TABLE event_players_away TO event_players');

        $this->assertSame('Jo Smith', $this->people->find(101)['name']);
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(101));
        $this->assertSame(['uses' => 1, 'proposals' => 1, 'events' => 1, 'playgroup' => 1], $this->links(100));
    }

    private function assertMergeRefused(int $survivorId, int $loserId, string $message): void
    {
        $people = array_column($this->people->all(), 'id');
        try {
            $this->people->merge($survivorId, $loserId);
            $this->fail('The merge was not refused.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame($message, $error->getMessage());
        }
        $this->assertSame($people, array_column($this->people->all(), 'id'));
    }

    /** Link 100 and 101 to one of owner 1's uses, proposals, events and playgroup slots, and 200 to owner 2's. */
    private function seedLinks(): void
    {
        $this->runSql("
            INSERT INTO uses (id, artifact_id, user_id, use_date) VALUES (2, 20, 2, '2026-02-02');
            INSERT INTO uses_players (use_id, player_id, user_id) VALUES (1, 100, 1), (1, 101, 1), (2, 200, 2);
            INSERT INTO proposal_outcomes (id, user_id, item_id, proposal_date, outcome, note) VALUES
                (1, 1, 10, '2026-03-01', 'explicit_decline', ''), (2, 2, 20, '2026-03-01', 'explicit_decline', '');
            INSERT INTO proposal_outcome_players VALUES (1, 100), (1, 101), (2, 200);
            INSERT INTO events (id, user_id, name) VALUES (1, 1, 'Beach week'), (2, 2, 'Their week');
            INSERT INTO event_players VALUES (1, 100), (1, 101), (2, 200);
            INSERT INTO playgroup (FullName, user_id) VALUES (100, 1), (101, 1), (200, 2);
        ");
    }

    private function links(int $playerId): array
    {
        $count = fn (string $sql) => (int) $this->db->query($sql . $playerId)->fetch_row()[0];
        return [
            'uses' => $count('SELECT COUNT(*) FROM uses_players WHERE player_id = '),
            'proposals' => $count('SELECT COUNT(*) FROM proposal_outcome_players WHERE player_id = '),
            'events' => $count('SELECT COUNT(*) FROM event_players WHERE player_id = '),
            'playgroup' => $count('SELECT COUNT(*) FROM playgroup WHERE FullName = '),
        ];
    }

    private function accountLink(int $userId = 1): ?int
    {
        $link = $this->db->query('SELECT player_id FROM users WHERE id = ' . $userId)->fetch_row()[0];
        return $link === null ? null : (int) $link;
    }

    private function fullName(int $id): ?string
    {
        return $this->db->query('SELECT FullName FROM players WHERE id = ' . $id)->fetch_row()[0];
    }
}
