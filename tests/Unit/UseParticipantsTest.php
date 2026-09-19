<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/use_participants.php';

class UseParticipantsTest extends TestCase
{
    private function useRow(int $id): array
    {
        return ['id' => $id, 'artifact_id' => 7, 'use_date' => '2019-05-01', 'note' => '', 'notesTwo' => ''];
    }

    public function test_participants_attach_to_matching_use(): void
    {
        $uses = [$this->useRow(1), $this->useRow(2)];
        $rows = [
            ['use_id' => 1, 'player_id' => 10, 'FirstName' => 'Melissa', 'LastName' => 'Stephens'],
        ];

        $result = attach_participants_to_uses($uses, $rows);

        $this->assertCount(1, $result[0]['participants']);
        $this->assertSame(10, $result[0]['participants'][0]['id']);
        $this->assertSame([], $result[1]['participants']);
    }

    public function test_duplicate_junction_rows_collapse_to_one_participant(): void
    {
        $uses = [$this->useRow(1)];
        $rows = [
            ['use_id' => 1, 'player_id' => 10, 'FirstName' => 'Melissa', 'LastName' => 'Stephens'],
            ['use_id' => 1, 'player_id' => 10, 'FirstName' => 'Melissa', 'LastName' => 'Stephens'],
            ['use_id' => 1, 'player_id' => 10, 'FirstName' => 'Melissa', 'LastName' => 'Stephens'],
        ];

        $result = attach_participants_to_uses($uses, $rows);

        $this->assertCount(1, $result[0]['participants']);
    }

    public function test_rows_for_unknown_uses_are_ignored(): void
    {
        $uses = [$this->useRow(1)];
        $rows = [
            ['use_id' => 999, 'player_id' => 10, 'FirstName' => 'Ghost', 'LastName' => 'Player'],
        ];

        $result = attach_participants_to_uses($uses, $rows);

        $this->assertSame([], $result[0]['participants']);
    }

    public function test_input_rows_are_not_mutated(): void
    {
        $uses = [$this->useRow(1)];
        $rows = [];

        $result = attach_participants_to_uses($uses, $rows);

        $this->assertArrayNotHasKey('participants', $uses[0]);
        $this->assertSame([], $result[0]['participants']);
    }
}
