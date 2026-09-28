<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/items_list.php';
require_once PROJECT_PATH . '/private/event_plan.php';

/**
 * Seams:
 * - event_plan_groups(): an event's items grouped by one dimension, then
 *   optionally by another
 * - event_plan_text(): those groups as a plain-text packing checklist
 * - event_plan_grouping(): the grouping a request asks for, over the saved one
 * - event_player_ages(): how many players are adults, and children at each age
 */
class EventPlanTest extends TestCase
{
    private function item(string $title, array $fields = []): array
    {
        return $fields + [
            'id' => crc32($title),
            'Title' => $title,
            'MnP' => null,
            'MxP' => null,
            'SS' => '',
            'Age' => null,
            'tags' => [],
            'setting' => '',
            'note' => '',
            'is_packed' => false,
        ];
    }

    private function labels(array $groups): array
    {
        return array_column($groups, 'label');
    }

    private function titles(array $group): array
    {
        return array_column($group['items'], 'Title');
    }

    public function test_a_game_with_several_sweet_spots_shows_under_each_player_count_largest_first(): void
    {
        $groups = event_plan_groups([
            $this->item('Wavelength', ['SS' => '6,8']),
            $this->item('Ra', ['SS' => '3-4']),
            $this->item('Sky Team', ['SS' => '2']),
        ], 'players');

        $this->assertSame(['8 players', '6 players', '4 players', '3 players', '2 players'], $this->labels($groups));
        $this->assertSame(['Wavelength'], $this->titles($groups[0]));
        $this->assertSame(['Wavelength'], $this->titles($groups[1]));
        $this->assertSame(['Ra'], $this->titles($groups[2]));
        $this->assertSame(['Ra'], $this->titles($groups[3]));
    }

    public function test_items_without_the_grouped_value_collect_in_a_last_group(): void
    {
        $groups = event_plan_groups([
            $this->item('Hive Pocket'),
            $this->item('Sky Team', ['SS' => '2']),
        ], 'players');

        $this->assertSame(['2 players', 'No sweet spot'], $this->labels($groups));
        $this->assertSame(['Hive Pocket'], $this->titles($groups[1]));
    }

    public function test_items_in_a_group_are_in_title_order(): void
    {
        $groups = event_plan_groups([
            $this->item('Tigris and Euphrates', ['SS' => '4']),
            $this->item('chicago Express', ['SS' => '4']),
            $this->item('Imperial 2030', ['SS' => '4']),
        ], 'players');

        $this->assertSame(['chicago Express', 'Imperial 2030', 'Tigris and Euphrates'], $this->titles($groups[0]));
    }

    public function test_grouping_by_age_runs_youngest_first(): void
    {
        $groups = event_plan_groups([
            $this->item('Hanabi', ['Age' => 10]),
            $this->item("Loopin' Louie", ['Age' => 4]),
            $this->item('Junk Art', ['Age' => 6]),
            $this->item('Hive Pocket'),
        ], 'age');

        $this->assertSame(['Age 4+', 'Age 6+', 'Age 10+', 'No age recorded'], $this->labels($groups));
    }

    public function test_grouping_by_setting_ignores_case_and_keeps_the_first_titles_spelling(): void
    {
        $groups = event_plan_groups([
            $this->item('Sky Team', ['setting' => ' beach ']),
            $this->item('Ra', ['setting' => 'Beach']),
            $this->item('Agricola', ['setting' => 'House']),
            $this->item('Wavelength'),
        ], 'setting');

        $this->assertSame(['Beach', 'House', 'No setting'], $this->labels($groups));
        $this->assertSame(['Ra', 'Sky Team'], $this->titles($groups[0]));
    }

    public function test_grouping_by_tag_puts_a_game_under_each_of_its_tags(): void
    {
        $groups = event_plan_groups([
            $this->item('Just One', ['tags' => ['casual', 'party']]),
            $this->item('Antike II', ['tags' => ['strategy']]),
            $this->item('Senji'),
        ], 'tag');

        $this->assertSame(['casual', 'party', 'strategy', 'Untagged'], $this->labels($groups));
        $this->assertSame(['Just One'], $this->titles($groups[1]));
    }

    public function test_no_grouping_is_one_unlabelled_group_of_everything(): void
    {
        $groups = event_plan_groups([$this->item('Ra'), $this->item('Hive Pocket')], 'none');

        $this->assertSame([''], $this->labels($groups));
        $this->assertSame(['Hive Pocket', 'Ra'], $this->titles($groups[0]));
    }

    public function test_an_unknown_dimension_means_no_grouping(): void
    {
        $groups = event_plan_groups([$this->item('Ra', ['SS' => '3'])], 'colour');

        $this->assertSame([''], $this->labels($groups));
    }

    public function test_player_counts_can_be_sub_grouped_by_tag_with_untagged_games_first_and_unlabelled(): void
    {
        $groups = event_plan_groups([
            $this->item('Guards of Atlantis II', ['SS' => '4,6,8', 'tags' => ['strategy']]),
            $this->item('Wavelength', ['SS' => '6,8', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6']),
        ], 'players', 'tag');

        $this->assertSame(['8 players', '6 players', '4 players'], $this->labels($groups));
        $this->assertSame(['casual', 'strategy'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Wavelength'], $this->titles($groups[0]['groups'][0]));
        $this->assertSame(['', 'casual', 'strategy'], $this->labels($groups[1]['groups']));
        $this->assertSame(['Senji'], $this->titles($groups[1]['groups'][0]));
    }

    public function test_only_the_chosen_tags_make_sub_groups(): void
    {
        $groups = event_plan_groups([
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['beach-safe', 'casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['Main']]),
            $this->item('Hot Streak', ['SS' => '6', 'tags' => ['beach-safe']]),
        ], 'players', 'tag', ['casual', 'main']);

        $this->assertSame(['', 'casual', 'Main'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Hot Streak'], $this->titles($groups[0]['groups'][0]));
    }

    public function test_the_chosen_tags_come_in_the_order_given(): void
    {
        $groups = event_plan_groups([
            $this->item('Wavelength', ['tags' => ['casual']]),
            $this->item('Senji', ['tags' => ['main']]),
        ], 'tag', 'none', ['main', 'casual']);

        $this->assertSame(['main', 'casual'], $this->labels($groups));
    }

    public function test_sub_grouping_by_the_same_dimension_is_ignored(): void
    {
        $groups = event_plan_groups([$this->item('Ra', ['SS' => '3'])], 'players', 'players');

        $this->assertSame([], $groups[0]['groups']);
    }

    public function test_the_checklist_reads_like_a_hand_written_packing_list(): void
    {
        $groups = event_plan_groups([
            $this->item('Guards of Atlantis II', ['MnP' => 4, 'MxP' => 8, 'SS' => '4,6,8', 'tags' => ['strategy']]),
            $this->item('Hanabi', ['MnP' => 2, 'MxP' => 5, 'SS' => '4', 'Age' => 10, 'tags' => ['casual'],
                'note' => 'requested by mom', 'is_packed' => true]),
            $this->item('Sky Team', ['MnP' => 2, 'MxP' => 2, 'SS' => '2', 'setting' => 'beach']),
        ], 'players', 'tag');

        $this->assertSame(
            "# 8 players\n"
            . "## strategy\n"
            . "- [ ] Guards of Atlantis II, 4–8 (4, 6, 8)\n"
            . "\n"
            . "# 6 players\n"
            . "## strategy\n"
            . "- [ ] Guards of Atlantis II, 4–8 (4, 6, 8)\n"
            . "\n"
            . "# 4 players\n"
            . "## casual\n"
            . "- [x] Hanabi, 2–5 (4), 10 yrs, requested by mom\n"
            . "## strategy\n"
            . "- [ ] Guards of Atlantis II, 4–8 (4, 6, 8)\n"
            . "\n"
            . "# 2 players\n"
            . "- [ ] Sky Team, 2 (2), beach\n",
            event_plan_text($groups)
        );
    }

    public function test_an_ungrouped_checklist_is_just_the_lines(): void
    {
        $groups = event_plan_groups([$this->item('Ra'), $this->item('Hive Pocket')], 'none');

        $this->assertSame("- [ ] Hive Pocket\n- [ ] Ra\n", event_plan_text($groups));
    }

    public function test_event_dates_read_as_a_short_span(): void
    {
        $this->assertSame('Jul 3 – Jul 10, 2027', event_dates_label('2027-07-03', '2027-07-10'));
        $this->assertSame('Dec 30, 2027 – Jan 2, 2028', event_dates_label('2027-12-30', '2028-01-02'));
        $this->assertSame('Jul 3, 2027', event_dates_label('2027-07-03', null));
        $this->assertSame('Jul 3, 2027', event_dates_label('2027-07-03', '2027-07-03'));
        $this->assertSame('Until Jul 10, 2027', event_dates_label(null, '2027-07-10'));
        $this->assertSame('', event_dates_label(null, null));
    }

    public function test_a_game_not_kept_says_so_on_the_checklist(): void
    {
        $groups = event_plan_groups([
            $this->item("That's Not a Hat", ['MnP' => 3, 'MxP' => 8, 'SS' => '5,6', 'Age' => 8,
                'note' => 'maybe buy', 'is_kept' => false]),
            $this->item('Ra', ['is_kept' => true]),
        ], 'none');

        $this->assertSame("- [ ] Ra\n- [ ] That's Not a Hat, 3–8 (5, 6), 8 yrs, maybe buy, not kept\n", event_plan_text($groups));
    }

    public function test_the_details_are_the_checklist_line_after_the_title(): void
    {
        $hanabi = $this->item('Hanabi', ['MnP' => 2, 'MxP' => 5, 'SS' => '4', 'Age' => 10,
            'setting' => 'beach', 'note' => 'requested by mom', 'is_kept' => false]);

        $this->assertSame(', 2–5 (4), 10 yrs, beach, requested by mom, not kept', event_plan_details($hanabi));
        $this->assertSame('', event_plan_details($this->item('Hive Pocket')));
    }

    public function test_naming_tags_sub_groups_by_tag_when_no_second_grouping_is_chosen(): void
    {
        $this->assertSame(
            ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main'],
            event_plan_grouping(['by' => 'players', 'then' => 'none', 'tags' => 'Casual,main'], [])
        );
    }

    public function test_naming_tags_leaves_a_chosen_second_grouping_or_a_tag_grouping_alone(): void
    {
        $this->assertSame('age', event_plan_grouping(['by' => 'players', 'then' => 'age', 'tags' => 'casual'], [])['then']);
        $this->assertSame('none', event_plan_grouping(['by' => 'tag', 'then' => 'none', 'tags' => 'casual'], [])['then']);
    }

    public function test_the_saved_grouping_fills_in_what_the_request_leaves_out(): void
    {
        $saved = ['by' => 'age', 'then' => 'tag', 'tags' => 'casual, main'];

        $this->assertSame($saved, event_plan_grouping([], $saved));
        $this->assertSame(['by' => 'players', 'then' => 'none', 'tags' => ''], event_plan_grouping([], []));
        $this->assertSame('players', event_plan_grouping(['by' => 'colour'], [])['by']);
    }

    public function test_clearing_the_tags_keeps_the_chosen_second_grouping(): void
    {
        $this->assertSame(
            ['by' => 'players', 'then' => 'none', 'tags' => ''],
            event_plan_grouping(['by' => 'players', 'then' => 'none', 'tags' => ''], ['by' => 'players', 'then' => 'tag', 'tags' => 'casual'])
        );
    }

    public function test_the_line_gives_the_play_time_after_the_age(): void
    {
        $this->assertSame(', 2–5 (4), 10 yrs, 25 min',
            event_plan_details($this->item('Hanabi', ['MnP' => 2, 'MxP' => 5, 'SS' => '4', 'Age' => 10, 'MnT' => 25, 'MxT' => 25])));
        $this->assertSame(', 30–60 min, beach',
            event_plan_details($this->item('Ra', ['MnT' => 30, 'MxT' => 60, 'setting' => 'beach'])));
        $this->assertSame(', 45 min', event_plan_details($this->item('Senji', ['MxT' => 45])));
        $this->assertSame('', event_plan_details($this->item('Hive Pocket', ['MnT' => 0, 'MxT' => null])));
    }

    private function players(array $ages): array
    {
        return array_map(fn($age) => ['id' => 1, 'name' => 'Someone', 'age' => $age], $ages);
    }

    public function test_player_ages_count_adults_then_children_oldest_first(): void
    {
        $this->assertSame(
            '2 adults (18+) · 4 children: 1 age 17, 1 age 12, 2 age 8 · 1 age unknown',
            event_player_ages($this->players([40, 8, 18, 12, 8, null, 17]))
        );
    }

    public function test_player_ages_leave_out_what_is_not_there(): void
    {
        $this->assertSame('1 adult (18+)', event_player_ages($this->players([30])));
        $this->assertSame('1 child: 1 age 5', event_player_ages($this->players([5])));
        $this->assertSame('', event_player_ages([]));
    }
}
