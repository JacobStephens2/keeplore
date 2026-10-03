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

    public function test_use_count_defaults_to_one(): void
    {
        $this->assertSame(1, record_use_count([]));
        $this->assertSame(1, record_use_count(['useCount' => '']));
        $this->assertSame(1, record_use_count(['useCount' => 'abc']));
    }

    public function test_use_count_reads_the_number_of_uses(): void
    {
        $this->assertSame(2, record_use_count(['useCount' => '2']));
    }

    public function test_use_count_is_clamped_to_one_through_the_max(): void
    {
        $this->assertSame(1, record_use_count(['useCount' => '0']));
        $this->assertSame(1, record_use_count(['useCount' => '-3']));
        $this->assertSame(\Uses::MAX_COUNT, record_use_count(['useCount' => '500']));
    }

    public function test_success_message_names_how_many_uses_when_more_than_one(): void
    {
        $this->assertSame(
            'The interaction with Old Maid with 1 person was recorded 2 times.',
            record_use_success_message([
                'artifact' => ['name' => 'Old Maid'],
                'user' => [['id' => 2, 'name' => 'Sam Lee']],
                'useCount' => '2',
            ])
        );
    }

    public function test_input_maps_the_record_use_post_onto_the_uses_module(): void
    {
        $this->assertSame([
            'item_id' => '12',
            'use_date' => '2026-10-02',
            'setting' => 'Cabin',
            'notes' => 'Close game',
            'player_ids' => ['1', '', '2'],
            'count' => '2',
        ], record_use_input([
            'artifact' => ['id' => '12', 'name' => 'Old Maid'],
            'user' => [
                ['id' => '1', 'name' => 'Local Dev'],
                ['id' => '', 'name' => 'Typed but not picked'],
                ['id' => '2', 'name' => 'Sam Lee'],
            ],
            'useDate' => '2026-10-02',
            'Note' => 'Cabin',
            'NotesTwo' => 'Close game',
            'useCount' => '2',
        ]));
    }

    public function test_input_from_a_quick_record_is_one_use_with_blank_notes(): void
    {
        $this->assertSame([
            'item_id' => '',
            'use_date' => '',
            'setting' => '',
            'notes' => '',
            'player_ids' => [],
            'count' => 1,
        ], record_use_input([]));
    }

    public function test_group_keeps_the_people_date_and_setting_of_a_recorded_use(): void
    {
        $group = record_use_group([
            'artifact' => ['id' => '12', 'name' => 'Old Maid'],
            'user' => [
                ['id' => '1', 'name' => 'Local Dev'],
                ['id' => '2', 'name' => ' Sam Lee '],
            ],
            'useDate' => '2026-10-02',
            'Note' => 'Grandma\'s',
            'NotesTwo' => 'Close game',
            'useCount' => '2',
        ], '2026-10-03');

        $this->assertSame([
            'people' => [
                ['id' => 1, 'name' => 'Local Dev'],
                ['id' => 2, 'name' => 'Sam Lee'],
            ],
            'useDate' => '2026-10-02',
            'Note' => 'Grandma\'s',
            'savedOn' => '2026-10-03',
        ], $group);
    }

    public function test_group_drops_blank_and_repeated_people(): void
    {
        $group = record_use_group([
            'user' => [
                ['id' => '', 'name' => 'Typed but not picked'],
                ['id' => '2', 'name' => 'Sam Lee'],
                ['id' => '2', 'name' => 'Sam Lee'],
            ],
        ], '2026-10-03');

        $this->assertSame([['id' => 2, 'name' => 'Sam Lee']], $group['people']);
        $this->assertSame('', $group['useDate']);
        $this->assertSame('', $group['Note']);
    }

    public function test_form_opens_with_the_group_when_recording_again(): void
    {
        $form = record_use_form($this->group(), true, $this->fallback());

        $this->assertSame($this->group()['people'], $form['people']);
        $this->assertSame('2026-10-02', $form['useDate']);
        $this->assertSame('Cabin', $form['Note']);
        $this->assertNull($form['offerGroup']);
    }

    public function test_form_offers_the_group_but_opens_fresh_otherwise(): void
    {
        $form = record_use_form($this->group(), false, $this->fallback());

        $this->assertSame($this->fallback()['people'], $form['people']);
        $this->assertSame('2026-10-03', $form['useDate']);
        $this->assertSame('Home', $form['Note']);
        $this->assertSame($this->group(), $form['offerGroup']);
    }

    public function test_form_opens_fresh_without_a_group_even_when_asked_again(): void
    {
        foreach ([null, ['people' => [], 'useDate' => '', 'Note' => '']] as $group) {
            $form = record_use_form($group, true, $this->fallback());

            $this->assertSame($this->fallback()['people'], $form['people']);
            $this->assertNull($form['offerGroup']);
        }
    }

    public function test_form_uses_today_when_the_group_was_saved_on_an_earlier_day(): void
    {
        $group = ['savedOn' => '2026-09-28'] + $this->group();

        $form = record_use_form($group, true, $this->fallback());

        $this->assertSame('2026-10-03', $form['useDate']);
        $this->assertSame($group['people'], $form['people']);
    }

    public function test_form_keeps_todays_date_when_the_group_has_none(): void
    {
        $group = ['useDate' => ''] + $this->group();

        $this->assertSame('2026-10-03', record_use_form($group, true, $this->fallback())['useDate']);
    }

    /** @return array{people: list<array{id: int, name: string}>, useDate: string, Note: string} */
    private function group(): array
    {
        return [
            'people' => [['id' => 2, 'name' => 'Sam Lee']],
            'useDate' => '2026-10-02',
            'Note' => 'Cabin',
            'savedOn' => '2026-10-03',
        ];
    }

    /** @return array{people: list<array{id: int, name: string}>, useDate: string, Note: string} */
    private function fallback(): array
    {
        return [
            'people' => [['id' => 1, 'name' => 'Local Dev']],
            'useDate' => '2026-10-03',
            'Note' => 'Home',
        ];
    }
}
