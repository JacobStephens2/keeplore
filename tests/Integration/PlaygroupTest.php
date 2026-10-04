<?php

namespace Tests\Integration;

use Playgroup;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Playgroup, the owner's playgroup. Owner 1 has Sam Lee (100) and Jo
 * Smith (101) in slots 1 and 2; owner 2 has Other Person (200) in slot 3.
 */
final class PlaygroupTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Playgroup $playgroup;

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
        $this->db->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        $this->runSql("
            ALTER TABLE players ADD COLUMN G VARCHAR(10), ADD COLUMN Priority INT;
            ALTER TABLE responses ADD COLUMN Player INT, ADD COLUMN AversionDate DATE, ADD COLUMN PassDate DATE, ADD COLUMN RequestDate DATE;
            CREATE TABLE playgroup (ID INT PRIMARY KEY AUTO_INCREMENT, FullName INT NOT NULL, user_id INT NOT NULL) ENGINE=InnoDB;
            INSERT INTO playgroup (ID, FullName, user_id) VALUES (1, 100, 1), (2, 101, 1), (3, 200, 2);
        ");
        require_once PRIVATE_PATH . '/classes/Aversions.php';
        require_once PRIVATE_PATH . '/classes/Playgroup.php';
        $this->playgroup = new Playgroup($this->db, 1);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_members_are_only_the_owners_slots(): void
    {
        $this->assertSame([
            ['id' => 1, 'person_id' => 100, 'name' => 'Sam Lee'],
            ['id' => 2, 'person_id' => 101, 'name' => 'Jo Smith'],
        ], $this->playgroup->members());
        $this->assertSame([3], array_column((new Playgroup($this->db, 2))->members(), 'id'));
    }

    public function test_member_is_the_owners_slot_or_null(): void
    {
        $this->assertSame(['id' => 2, 'person_id' => 101, 'name' => 'Jo Smith'], $this->playgroup->member(2));
        $this->assertNull($this->playgroup->member(3));
        $this->assertNull($this->playgroup->member(999));
    }

    public function test_replace_puts_another_of_the_owners_people_in_the_slot(): void
    {
        $this->playgroup->replace(1, 101);

        $this->assertSame([[1, 101, 1], [2, 101, 1], [3, 200, 2]], $this->slots());
    }

    public function test_replace_refuses_another_owners_slot_and_leaves_it_unchanged(): void
    {
        try {
            $this->playgroup->replace(3, 100);
            $this->fail('The slot was not refused.');
        } catch (\OutOfBoundsException) {
        }

        $this->assertSame([[1, 100, 1], [2, 101, 1], [3, 200, 2]], $this->slots());
    }

    public function test_replace_refuses_another_owners_person_and_writes_nothing(): void
    {
        foreach ([200, 0] as $personId) {
            try {
                $this->playgroup->replace(1, $personId);
                $this->fail('The person was not refused.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([[1, 100, 1], [2, 101, 1], [3, 200, 2]], $this->slots());
    }

    public function test_remove_empties_the_owners_slot(): void
    {
        $this->playgroup->remove(1);

        $this->assertSame([[2, 101, 1], [3, 200, 2]], $this->slots());
    }

    public function test_remove_refuses_another_owners_slot_and_leaves_it_unchanged(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        try {
            $this->playgroup->remove(3);
        } finally {
            $this->assertSame([[1, 100, 1], [2, 101, 1], [3, 200, 2]], $this->slots());
        }
    }

    public function test_add_puts_the_owners_people_in_new_slots_skipping_blank_choices(): void
    {
        $this->playgroup->add(['101', '', 100, null]);

        $this->assertSame([[1, 100, 1], [2, 101, 1], [3, 200, 2], [4, 101, 1], [5, 100, 1]], $this->slots());
    }

    public function test_add_refuses_another_owners_person_and_writes_nothing(): void
    {
        foreach ([['100', '200'], ['100', 'Invalid']] as $personIds) {
            try {
                $this->playgroup->add($personIds);
                $this->fail('The people were not refused.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([[1, 100, 1], [2, 101, 1], [3, 200, 2]], $this->slots());
    }

    public function test_add_with_nobody_chosen_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->playgroup->add(['', '']);
        } finally {
            $this->assertCount(3, $this->slots());
        }
    }

    public function test_choose_filtered_on_a_type_is_the_owners_items_of_that_type_id(): void
    {
        $this->db->query("UPDATE games SET type = 'stale name'");
        $this->db->query("INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES (10, 1, 100, '2026-02-01'), (12, 1, 100, '2026-02-02')");

        $titles = fn (array $typeIds) => array_column($this->playgroup->choose($typeIds, false, false), 'title');

        $this->assertSame(['Catan'], $titles(['1']));
        $this->assertEqualsCanonicalizing(['Arrival', 'Catan'], $titles(['1', '2']));
        $this->assertSame([], $titles([]));
    }

    public function test_choose_keeps_only_kept_items_when_asked(): void
    {
        $this->db->query("INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES (10, 1, 100, '2026-02-01'), (12, 1, 100, '2026-02-02')");

        $this->assertSame(['Catan'], array_column($this->playgroup->choose(['1', '2'], false, true), 'title'));
    }

    public function test_choose_gives_each_member_their_latest_responses_to_each_item(): void
    {
        $this->db->query("INSERT INTO responses (Title, user_id, Player, PlayDate, AversionDate) VALUES
            (10, 1, 100, '2026-02-01', NULL), (10, 1, 100, '2026-03-01', NULL), (10, 1, 101, NULL, '2026-01-05')");

        $rows = $this->playgroup->choose(['1'], false, false);

        $this->assertSame([[10, 100, '2026-03-01', null], [10, 101, null, '2026-01-05']], array_map(
            fn (array $row) => [(int) $row['id'], (int) $row['PlayerID'], $row['MaxOfPlayDate'], $row['MaxOfAversionDate']],
            $rows
        ));
        $this->assertSame('Sam', $rows[0]['FirstName']);
    }

    public function test_choose_links_each_member_to_their_highest_aversion_id_of_the_item_or_to_nothing(): void
    {
        $this->db->query("INSERT INTO responses (id, Title, user_id, Player, PlayDate, AversionDate) VALUES
            (1, 10, 1, 100, '2026-02-01', NULL), (2, 10, 1, 101, NULL, '2026-01-05'),
            (3, 10, 1, 101, '2026-02-01', NULL), (4, 10, 1, 101, NULL, '2025-12-01'), (5, 10, 1, 101, '2026-03-01', NULL)");

        $this->assertSame([[100, null], [101, 4]], array_map(
            fn (array $row) => [(int) $row['PlayerID'], $row['AversionID']],
            $this->playgroup->choose(['1'], false, false)
        ));
    }

    public function test_choose_ignores_the_other_owners_slots_people_and_responses(): void
    {
        $this->runSql("
            INSERT INTO playgroup (FullName, user_id) VALUES (200, 1), (101, 2);
            INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES
                (10, 1, 100, '2026-02-01'),
                (10, 2, 101, '2026-02-02'),
                (10, 1, 200, '2026-02-03'),
                (20, 2, 200, '2026-02-04');
        ");

        $rows = $this->playgroup->choose(['1'], false, false);

        $this->assertSame([[10, 100]], array_map(fn (array $row) => [(int) $row['id'], (int) $row['PlayerID']], $rows));
    }

    public function test_matching_count_uses_the_owners_distinct_group_size(): void
    {
        // Owner 1's group is 2 people in 3 slots; every account's slots number 6.
        $this->runSql("
            INSERT INTO playgroup (FullName, user_id) VALUES (100, 1), (200, 2), (200, 2);
            UPDATE games SET mnp = 2, mxp = 2 WHERE id = 10;
            UPDATE games SET mnp = 3, mxp = 3 WHERE id = 11;
            UPDATE games SET mnp = 6, mxp = 6 WHERE id = 13;
            INSERT INTO responses (Title, user_id, Player, PlayDate) VALUES
                (10, 1, 100, '2026-02-01'), (11, 1, 100, '2026-02-01'), (13, 1, 100, '2026-02-01');
        ");

        $this->assertSame(['Catan'], array_column($this->playgroup->choose(['1'], true, false), 'title'));
        $this->assertEqualsCanonicalizing(
            ['Catan', 'Azul', 'Former possession'],
            array_column($this->playgroup->choose(['1'], false, false), 'title')
        );
    }

    /** Every slot of every owner as [slot id, person id, owner id]. */
    private function slots(): array
    {
        return array_map(
            fn (array $row) => array_map('intval', $row),
            $this->db->query('SELECT ID, FullName, user_id FROM playgroup ORDER BY ID')->fetch_all(MYSQLI_NUM)
        );
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
}
