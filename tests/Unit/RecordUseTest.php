<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/record_use.php';

/**
 * Seam: private/record_use.php
 *
 * record_use_input() is the one map Record Use, Quick record, Edit User,
 * Edit Use, and the HTTP record use for the Item, date, Setting, notes,
 * and People. Participant defaults and form prefill remain shared helpers.
 * Submission answers and Use groups are covered through RecordUse::submit
 * in tests/Integration/RecordUseTest.php. The Setting a new use opens with
 * is read through Uses (tests/Integration/UsesTest.php).
 */
class RecordUseTest extends TestCase
{
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

    public function test_new_use_forms_open_with_the_uses_modules_last_setting(): void
    {
        foreach ([
            '/private/shared/quick_record_popup.php',
            '/ui/uses/record-new.php',
            '/private/shared/user_interactions.php',
        ] as $path) {
            $source = (string) file_get_contents(PROJECT_PATH . $path);
            $this->assertMatchesRegularExpression(
                "/->lastSetting\(\)\s*\?\?/",
                $source,
                $path . ' must open a new use with the Uses module\'s last Setting, falling back to the default.'
            );
            $this->assertStringNotContainsString('most_recent_use_setting', $source);
        }
        $this->assertFalse(function_exists('most_recent_use_setting'));
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

    public function test_input_maps_an_edit_use_post_onto_the_uses_module(): void
    {
        $this->assertSame([
            'item_id' => '12',
            'use_date' => '2026-10-02',
            'setting' => 'Cabin',
            'notes' => 'Close game',
            'player_ids' => ['1', '2'],
        ], record_use_input([
            'artifact_id' => '12',
            'use_date' => '2026-10-02',
            'note' => 'Cabin',
            'notesTwo' => 'Close game',
            'user' => [
                ['id' => '1', 'name' => 'Local Dev'],
                ['id' => '2', 'name' => 'Sam Lee'],
            ],
        ]));
    }

    public function test_input_prefers_record_use_names_when_both_are_posted(): void
    {
        $this->assertSame([
            'item_id' => '12',
            'use_date' => '',
            'setting' => '',
            'notes' => 'Close game',
            'player_ids' => ['1', '', '2'],
            'count' => '',
        ], record_use_input([
            'artifact' => ['id' => '12', 'name' => 'Old Maid'],
            'artifact_id' => '99',
            'user' => [
                ['id' => '1', 'name' => ''],
                ['id' => '', 'name' => ''],
                ['id' => '2', 'name' => 'Sam Lee'],
            ],
            'useDate' => '',
            'use_date' => '1999-01-01',
            'Note' => '',
            'note' => 'Cabin',
            'NotesTwo' => 'Close game',
            'notesTwo' => 'Other notes',
            'useCount' => '',
            'count' => '9',
        ]));
    }

    public function test_input_drops_a_blank_edit_use_name_and_keeps_a_blank_record_use_name(): void
    {
        $rows = [
            ['id' => '1', 'name' => ''],
            ['id' => '9'],
            'not a person',
            ['id' => '3', 'name' => '   '],
            ['id' => '', 'name' => ''],
            ['id' => '2', 'name' => 'Sam Lee'],
        ];

        $this->assertSame(['3', '2'], record_use_input([
            'artifact_id' => '12',
            'user' => $rows,
        ])['player_ids']);
        $this->assertSame(['1', '9', '3', '', '2'], record_use_input([
            'useDate' => '2026-10-02',
            'user' => $rows,
        ])['player_ids']);
    }

    public function test_input_treats_an_artifact_list_alone_as_a_record_use_post(): void
    {
        $this->assertSame([
            'item_id' => '12',
            'use_date' => '',
            'setting' => '',
            'notes' => '',
            'player_ids' => [],
        ], record_use_input([
            'artifact' => ['id' => '12', 'name' => 'Old Maid'],
            'artifact_id' => '99',
            'use_date' => '1999-01-01',
            'note' => 'Cabin',
            'notesTwo' => 'Other notes',
        ]));
    }

    public function test_input_passes_a_non_text_setting_and_ignores_count(): void
    {
        $input = record_use_input([
            'artifact_id' => 10,
            'use_date' => '2026-09-12',
            'note' => ['x'],
            'notesTwo' => ['y'],
            'count' => 5,
            'user_id' => 2,
        ]);

        $this->assertSame(['x'], $input['setting']);
        $this->assertSame(['y'], $input['notes']);
        $this->assertArrayNotHasKey('count', $input);
        $this->assertSame([], $input['player_ids']);
    }

    public function test_input_records_no_people_when_the_people_value_is_not_a_list(): void
    {
        $this->assertSame([], record_use_input(['useDate' => '2026-10-02', 'user' => 'Sam'])['player_ids']);
        $this->assertSame([], record_use_input(['artifact_id' => '12', 'user' => 'Sam'])['player_ids']);
    }

    public function test_input_from_a_quick_record_is_one_use_with_blank_notes(): void
    {
        $this->assertSame([
            'item_id' => '',
            'use_date' => '',
            'setting' => '',
            'notes' => '',
            'player_ids' => [],
        ], record_use_input([]));
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
