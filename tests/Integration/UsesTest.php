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
            $this->assertSame([['id' => 100, 'name' => 'Sam Lee'], ['id' => 101, 'name' => 'Jo Smith']], $use['people']);
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
        $this->assertSame([['id' => 101, 'name' => 'Jo Smith']], $use['people']);
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
}
