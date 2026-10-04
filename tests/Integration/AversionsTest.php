<?php

namespace Tests\Integration;

use Aversions;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Aversions, the owner's legacy aversions. Owner 1 has Aversions 1
 * (Catan, Sam Lee) and 2 (Azul, Jo Smith) and a play-only response 3; owner
 * 2 has Aversion 4 (Private item, Other Person) and a play-only response 5.
 */
final class AversionsTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Aversions $aversions;

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
        $this->runSql("
            ALTER TABLE responses ADD COLUMN Player INT, ADD COLUMN AversionDate DATE, ADD COLUMN Note TEXT;
            INSERT INTO responses (id, Title, user_id, Player, PlayDate, AversionDate, Note) VALUES
                (1, 10, 1, 100, '2025-12-01', '2026-03-01', 'kept note'),
                (2, 11, 1, 101, NULL, '2026-02-01', NULL),
                (3, 10, 1, 101, '2026-01-01', NULL, NULL),
                (4, 20, 2, 200, NULL, '2026-03-05', NULL),
                (5, 20, 2, 200, '2026-01-02', NULL, NULL);
        ");
        require_once PRIVATE_PATH . '/classes/Aversions.php';
        $this->aversions = new Aversions($this->db, 1);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_all_is_the_owners_aversions_newest_first_without_plays(): void
    {
        $this->assertSame([
            ['id' => 1, 'item_id' => 10, 'item' => 'Catan', 'person_id' => 100, 'person' => 'Sam Lee', 'date' => '2026-03-01'],
            ['id' => 2, 'item_id' => 11, 'item' => 'Azul', 'person_id' => 101, 'person' => 'Jo Smith', 'date' => '2026-02-01'],
        ], $this->aversions->all());
        $this->assertSame([4], array_column((new Aversions($this->db, 2))->all(), 'id'));
    }

    public function test_reads_join_only_the_owners_items_and_people(): void
    {
        $this->db->query('UPDATE responses SET Title = 20, Player = 200 WHERE id = 2');

        $this->assertSame(
            ['id' => 2, 'item_id' => 20, 'item' => '', 'person_id' => 200, 'person' => '', 'date' => '2026-02-01'],
            $this->aversions->find(2)
        );
    }

    public function test_find_is_the_owners_aversion_or_null(): void
    {
        $this->assertSame(
            ['id' => 2, 'item_id' => 11, 'item' => 'Azul', 'person_id' => 101, 'person' => 'Jo Smith', 'date' => '2026-02-01'],
            $this->aversions->find(2)
        );
        $this->assertNull($this->aversions->find(4), "Another owner's aversion was found.");
        $this->assertNull($this->aversions->find(3), 'A play-only response was found.');
        $this->assertNull($this->aversions->find(999));
    }

    public function test_record_writes_one_aversion_per_chosen_person_skipping_blank_choices(): void
    {
        $this->aversions->record(12, '2026-04-01', ['101', '', 100, null]);

        $this->assertSame([[12, 101, '2026-04-01', 1], [12, 100, '2026-04-01', 1]], $this->responses('id > 5'));
    }

    public function test_record_refuses_bad_input_and_writes_nothing(): void
    {
        foreach ([
            'another owner\'s item' => [20, '2026-04-01', ['100']],
            'an unknown item' => [999, '2026-04-01', ['100']],
            'another owner\'s person' => [10, '2026-04-01', ['100', '200']],
            'a choice that is not a person' => [10, '2026-04-01', ['Invalid']],
            'a date that is not a calendar date' => [10, '2026-02-30', ['100']],
            'a blank date' => [10, '', ['100']],
            'nobody chosen' => [10, '2026-04-01', ['', '']],
        ] as $case => [$itemId, $date, $personIds]) {
            try {
                $this->aversions->record($itemId, $date, $personIds);
                $this->fail("Recording with $case was not refused.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->responses('id > 5'));
    }

    public function test_update_writes_the_item_person_and_date_and_keeps_the_play_date_and_note(): void
    {
        $this->aversions->update(1, 12, 101, '2026-05-01');

        $this->assertSame(
            ['id' => 1, 'item_id' => 12, 'item' => 'Arrival', 'person_id' => 101, 'person' => 'Jo Smith', 'date' => '2026-05-01'],
            $this->aversions->find(1)
        );
        $this->assertSame(
            ['PlayDate' => '2025-12-01', 'Note' => 'kept note'],
            $this->db->query('SELECT PlayDate, Note FROM responses WHERE id = 1')->fetch_assoc()
        );
    }

    public function test_update_refuses_bad_input_and_leaves_the_aversion_unchanged(): void
    {
        foreach ([[20, 100, '2026-05-01'], [10, 200, '2026-05-01'], [10, 0, '2026-05-01'], [10, 100, '2026-13-01']] as [$itemId, $personId, $date]) {
            try {
                $this->aversions->update(1, $itemId, $personId, $date);
                $this->fail('The update was not refused.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([[10, 100, '2026-03-01', 1]], $this->responses('id = 1'));
    }

    public function test_update_and_remove_refuse_another_owners_aversion_and_a_play_and_leave_them_unchanged(): void
    {
        $before = $this->responses('id IN (3, 4)');
        foreach ([3, 4, 999] as $id) {
            foreach ([
                fn () => $this->aversions->update($id, 10, 100, '2026-05-01'),
                fn () => $this->aversions->remove($id),
            ] as $write) {
                try {
                    $write();
                    $this->fail("Response $id was not refused.");
                } catch (\OutOfBoundsException) {
                }
            }
        }

        $this->assertSame($before, $this->responses('id IN (3, 4)'));
    }

    public function test_remove_deletes_the_owners_aversion(): void
    {
        $this->aversions->remove(2);

        $this->assertNull($this->aversions->find(2));
        $this->assertSame([1, 3, 4, 5], array_map('intval', array_column($this->db->query('SELECT id FROM responses ORDER BY id')->fetch_all(), 0)));
    }

    /** The responses matching $where as [item id, person id, aversion date, owner id], in id order. */
    private function responses(string $where): array
    {
        return array_map(
            fn (array $row) => [(int) $row[0], (int) $row[1], $row[2], (int) $row[3]],
            $this->db->query("SELECT Title, Player, AversionDate, user_id FROM responses WHERE $where ORDER BY id")->fetch_all(MYSQLI_NUM)
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
