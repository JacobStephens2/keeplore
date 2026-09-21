<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/record_use.php';

/**
 * Seam: private/record_use.php
 *
 * The record-use write path (AJAX JSON, return URL, participants, last
 * setting) is one module so Edit User, Interact By, and Record Use share
 * it instead of each page inventing the same POST contract.
 */
class RecordUseTest extends TestCase
{
    public function test_ajax_payload_includes_the_new_use_for_the_table(): void
    {
        $payload = record_use_ajax_payload(
            [
                'artifact' => ['id' => '2807', 'name' => 'Catan'],
                'user' => [['id' => 2, 'name' => 'Sam Lee']],
                'useDate' => '2026-09-21',
            ],
            42,
            [
                'use_by_date' => '2027-03-20',
                'most_recent_use_date' => '2026-09-21',
                'is_overdue' => false,
            ],
            ['type' => 'board-game']
        );

        $this->assertSame(true, $payload['ok']);
        $this->assertSame(42, $payload['use_id']);
        $this->assertSame('2026-09-21', $payload['use_date']);
        $this->assertSame(2807, $payload['artifact_id']);
        $this->assertSame('Catan', $payload['artifact_name']);
        $this->assertSame('board-game', $payload['artifact_type']);
        $this->assertSame('2027-03-20', $payload['new_use_by_date']);
        $this->assertSame('2026-09-21', $payload['most_recent_use_date']);
        $this->assertFalse($payload['is_overdue']);
        $this->assertSame(
            'The interaction with Catan with 1 person was recorded.',
            $payload['message']
        );
    }

    public function test_ajax_payload_says_people_when_more_than_one_participant(): void
    {
        $payload = record_use_ajax_payload(
            [
                'artifact' => ['id' => 1, 'name' => 'Azul'],
                'user' => [
                    ['id' => 2, 'name' => 'Sam Lee'],
                    ['id' => 1, 'name' => 'Local Dev'],
                ],
                'useDate' => '2026-09-20',
            ],
            7,
            [],
            false
        );

        $this->assertSame('', $payload['artifact_type']);
        $this->assertSame(
            'The interaction with Azul with 2 people was recorded.',
            $payload['message']
        );
    }

    public function test_return_path_sends_user_edit_saves_back_to_that_user(): void
    {
        $this->assertSame(
            '/users/edit.php?id=23',
            record_use_return_path(
                ['return_to' => 'user-edit', 'return_player_id' => '23'],
                '/uses/record-new.php'
            )
        );
    }

    public function test_return_path_keeps_the_default_without_a_user_edit_return(): void
    {
        $this->assertSame(
            '/uses/record-new.php',
            record_use_return_path([], '/uses/record-new.php')
        );
        $this->assertSame(
            '/uses/record-new.php',
            record_use_return_path(
                ['return_to' => 'user-edit', 'return_player_id' => '0'],
                '/uses/record-new.php'
            )
        );
    }

    public function test_participants_are_the_edited_player_and_you_when_different(): void
    {
        $this->assertSame(
            [
                ['id' => 23, 'name' => 'Sam Lee'],
                ['id' => 1, 'name' => 'Local Dev'],
            ],
            record_use_participants(23, 'Sam Lee', 1, 'Local Dev')
        );
    }

    public function test_participants_are_only_the_edited_player_when_that_player_is_you(): void
    {
        $this->assertSame(
            [['id' => 1, 'name' => 'Local Dev']],
            record_use_participants(1, 'Local Dev', 1, 'Local Dev')
        );
    }

    public function test_most_recent_setting_is_the_last_use_note(): void
    {
        $query = function (string $sql) {
            if (strpos($sql, 'FROM uses') !== false) {
                return 'Kitchen table';
            }
            $this->fail('Should not fall back when a use note exists.');
        };

        $this->assertSame('Kitchen table', most_recent_use_setting(8, $query));
    }

    public function test_most_recent_setting_falls_back_to_the_user_default(): void
    {
        $query = function (string $sql) {
            if (strpos($sql, 'FROM uses') !== false) {
                return 'No results';
            }
            return 'Home';
        };

        $this->assertSame('Home', most_recent_use_setting(8, $query));
    }
}
