<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/items_list.php';
require_once PROJECT_PATH . '/private/event_plan.php';

/**
 * Seams, the Event plan module's interface:
 * - event_plan(): an event and a grouping in, the whole Event plan out: its
 *   groups, plain text, packed count, shopping list, smaller list, players'
 *   ages and settings
 * - event_plan_grouping(): the grouping a request asks for, over the saved one
 * - event_plan_details(): one item's checklist line after its title
 * - event_dates_label(): an event's dates
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

    /** The Event plan for these items and players under $grouping. */
    private function plan(array $items, array $grouping = [], array $players = []): array
    {
        return event_plan(['items' => $items, 'players' => $players], $grouping);
    }

    /** The plan's groups for these items under $grouping. */
    private function planGroups(array $items, array $grouping = [], array $players = []): array
    {
        return $this->plan($items, $grouping, $players)['groups'];
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
        $groups = $this->planGroups([
            $this->item('Wavelength', ['SS' => '6,8']),
            $this->item('Ra', ['SS' => '3-4']),
            $this->item('Sky Team', ['SS' => '2']),
        ], ['by' => 'players']);

        $this->assertSame(['8 players', '6 players', '4 players', '3 players', '2 players'], $this->labels($groups));
        $this->assertSame(['Wavelength'], $this->titles($groups[0]));
        $this->assertSame(['Wavelength'], $this->titles($groups[1]));
        $this->assertSame(['Ra'], $this->titles($groups[2]));
        $this->assertSame(['Ra'], $this->titles($groups[3]));
    }

    public function test_items_without_the_grouped_value_collect_in_a_last_group(): void
    {
        $groups = $this->planGroups([
            $this->item('Hive Pocket'),
            $this->item('Sky Team', ['SS' => '2']),
        ], ['by' => 'players']);

        $this->assertSame(['2 players', 'No sweet spot'], $this->labels($groups));
        $this->assertSame(['Hive Pocket'], $this->titles($groups[1]));
    }

    public function test_items_in_a_group_are_in_title_order(): void
    {
        $groups = $this->planGroups([
            $this->item('Tigris and Euphrates', ['SS' => '4']),
            $this->item('chicago Express', ['SS' => '4']),
            $this->item('Imperial 2030', ['SS' => '4']),
        ], ['by' => 'players']);

        $this->assertSame(['chicago Express', 'Imperial 2030', 'Tigris and Euphrates'], $this->titles($groups[0]));
    }

    public function test_grouping_by_age_runs_youngest_first(): void
    {
        $groups = $this->planGroups([
            $this->item('Hanabi', ['Age' => 10]),
            $this->item("Loopin' Louie", ['Age' => 4]),
            $this->item('Junk Art', ['Age' => 6]),
            $this->item('Hive Pocket'),
        ], ['by' => 'age']);

        $this->assertSame(['Age 4+', 'Age 6+', 'Age 10+', 'No age recorded'], $this->labels($groups));
    }

    public function test_grouping_by_setting_ignores_case_and_keeps_the_first_titles_spelling(): void
    {
        $groups = $this->planGroups([
            $this->item('Sky Team', ['setting' => ' beach ']),
            $this->item('Ra', ['setting' => 'Beach']),
            $this->item('Agricola', ['setting' => 'House']),
            $this->item('Wavelength'),
        ], ['by' => 'setting']);

        $this->assertSame(['Beach', 'House', 'No setting'], $this->labels($groups));
        $this->assertSame(['Ra', 'Sky Team'], $this->titles($groups[0]));
    }

    public function test_grouping_by_tag_puts_a_game_under_each_of_its_tags(): void
    {
        $groups = $this->planGroups([
            $this->item('Just One', ['tags' => ['casual', 'party']]),
            $this->item('Antike II', ['tags' => ['strategy']]),
            $this->item('Senji'),
        ], ['by' => 'tag']);

        $this->assertSame(['casual', 'party', 'strategy', 'Untagged'], $this->labels($groups));
        $this->assertSame(['Just One'], $this->titles($groups[1]));
    }

    public function test_no_grouping_is_one_unlabelled_group_of_everything(): void
    {
        $groups = $this->planGroups([$this->item('Ra'), $this->item('Hive Pocket')], ['by' => 'none']);

        $this->assertSame([''], $this->labels($groups));
        $this->assertSame(['Hive Pocket', 'Ra'], $this->titles($groups[0]));
    }

    public function test_an_unknown_dimension_falls_back_to_sweet_spot_then_nothing(): void
    {
        $items = [$this->item('Ra', ['SS' => '3']), $this->item('Senji', ['SS' => '4', 'tags' => ['main']])];

        $this->assertSame($this->plan($items, ['by' => 'players', 'then' => 'none']), $this->plan($items, ['by' => 'colour', 'then' => 'colour']));
    }

    public function test_player_counts_can_be_sub_grouped_by_tag_with_untagged_games_first_and_unlabelled(): void
    {
        $groups = $this->planGroups([
            $this->item('Guards of Atlantis II', ['SS' => '4,6,8', 'tags' => ['strategy']]),
            $this->item('Wavelength', ['SS' => '6,8', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6']),
        ], ['by' => 'players', 'then' => 'tag']);

        $this->assertSame(['8 players', '6 players', '4 players'], $this->labels($groups));
        $this->assertSame(['casual', 'strategy'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Wavelength'], $this->titles($groups[0]['groups'][0]));
        $this->assertSame(['', 'casual', 'strategy'], $this->labels($groups[1]['groups']));
        $this->assertSame(['Senji'], $this->titles($groups[1]['groups'][0]));
    }

    public function test_only_the_chosen_tags_make_sub_groups(): void
    {
        $groups = $this->planGroups([
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['beach-safe', 'casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['Main']]),
            $this->item('Hot Streak', ['SS' => '6', 'tags' => ['beach-safe']]),
        ], ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main']);

        $this->assertSame(['', 'casual', 'Main'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Hot Streak'], $this->titles($groups[0]['groups'][0]));
    }

    public function test_the_chosen_tags_come_in_the_order_given(): void
    {
        $groups = $this->planGroups([
            $this->item('Wavelength', ['tags' => ['casual']]),
            $this->item('Senji', ['tags' => ['main']]),
        ], ['by' => 'tag', 'tags' => 'main, casual']);

        $this->assertSame(['main', 'casual'], $this->labels($groups));
    }

    public function test_a_second_grouping_repeating_the_first_is_no_second_grouping(): void
    {
        $items = [
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6,8', 'tags' => ['main']]),
            $this->item('Ra', ['SS' => '4']),
        ];
        $plan = $this->plan($items, ['by' => 'players', 'then' => 'players', 'at_least' => 1]);

        $this->assertSame([], $plan['groups'][0]['groups']);
        $this->assertSame($this->plan($items, ['by' => 'players', 'then' => 'none', 'at_least' => 1]), $plan);
    }

    public function test_an_unnormalized_grouping_gives_the_same_plan_as_its_normalized_form(): void
    {
        $items = [
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['casual']]),
            $this->item('Just One', ['SS' => '6', 'tags' => ['Casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['main']]),
            $this->item('Outfoxed', ['SS' => '6', 'tags' => ['kids']]),
        ];

        $this->assertSame(
            $this->plan($items, ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main, kids', 'at_least' => 1, 'count_tags' => 'main, casual']),
            $this->plan($items, ['by' => 'players', 'then' => 'tag', 'tags' => ' Casual,MAIN,casual , kids', 'at_least' => 1, 'count_tags' => 'Main,,main, CASUAL'])
        );
    }

    public function test_the_checklist_reads_like_a_hand_written_packing_list(): void
    {
        $plan = $this->plan([
            $this->item('Guards of Atlantis II', ['MnP' => 4, 'MxP' => 8, 'SS' => '4,6,8', 'tags' => ['strategy']]),
            $this->item('Hanabi', ['MnP' => 2, 'MxP' => 5, 'SS' => '4', 'Age' => 10, 'tags' => ['casual'],
                'note' => 'requested by mom', 'is_packed' => true]),
            $this->item('Sky Team', ['MnP' => 2, 'MxP' => 2, 'SS' => '2', 'setting' => 'beach']),
        ], ['by' => 'players', 'then' => 'tag']);

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
            $plan['text']
        );
        $this->assertSame(1, $plan['packed']);
    }

    public function test_an_ungrouped_checklist_is_just_the_lines(): void
    {
        $plan = $this->plan([$this->item('Ra'), $this->item('Hive Pocket')], ['by' => 'none']);

        $this->assertSame("- [ ] Hive Pocket\n- [ ] Ra\n", $plan['text']);
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
        $plan = $this->plan([
            $this->item("That's Not a Hat", ['MnP' => 3, 'MxP' => 8, 'SS' => '5,6', 'Age' => 8,
                'note' => 'maybe buy', 'is_kept' => false]),
            $this->item('Ra', ['is_kept' => true]),
        ], ['by' => 'none']);

        $this->assertSame("- [ ] Ra\n- [ ] That's Not a Hat, 3–8 (5, 6), 8 yrs, maybe buy, not kept\n", $plan['text']);
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
            ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main', 'at_least' => 0, 'count_tags' => ''],
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
        $saved = ['by' => 'age', 'then' => 'tag', 'tags' => 'casual, main', 'at_least' => 2, 'count_tags' => 'main'];

        $this->assertSame($saved, event_plan_grouping([], $saved));
        $this->assertSame(['by' => 'players', 'then' => 'none', 'tags' => '', 'at_least' => 0, 'count_tags' => ''], event_plan_grouping([], []));
        $this->assertSame('players', event_plan_grouping(['by' => 'colour'], [])['by']);
    }

    public function test_clearing_the_tags_keeps_the_chosen_second_grouping(): void
    {
        $this->assertSame(
            ['by' => 'players', 'then' => 'none', 'tags' => '', 'at_least' => 0, 'count_tags' => ''],
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
            $this->plan([], [], $this->players([40, 8, 18, 12, 8, null, 17]))['player_ages']
        );
    }

    public function test_player_ages_leave_out_what_is_not_there(): void
    {
        $this->assertSame('1 adult (18+)', $this->plan([], [], $this->players([30]))['player_ages']);
        $this->assertSame('1 child: 1 age 5', $this->plan([], [], $this->players([5]))['player_ages']);
        $this->assertSame('', $this->plan([], [], [])['player_ages']);
    }

    public function test_by_players_ages_each_game_shows_under_the_youngest_player_old_enough(): void
    {
        $groups = $this->planGroups([
            $this->item('Catan', ['Age' => 10]),
            $this->item('Hungry Hippos', ['Age' => 2]),
            $this->item('Outfoxed', ['Age' => 5]),
            $this->item('Sushi Go', ['Age' => 6]),
            $this->item('Hive Pocket'),
        ], ['by' => 'player_age'], $this->players([40, 2, 6, 38, 6, null]));

        $this->assertSame(['Age 2', 'Age 6', 'Adults', 'No age recorded'], $this->labels($groups));
        $this->assertSame(['Hungry Hippos'], $this->titles($groups[0]));
        $this->assertSame(['Outfoxed', 'Sushi Go'], $this->titles($groups[1]));
        $this->assertSame(['Catan'], $this->titles($groups[2]));
        $this->assertSame(['Hive Pocket'], $this->titles($groups[3]));
    }

    public function test_by_players_ages_a_game_too_old_for_everyone_coming_collects_last(): void
    {
        $groups = $this->planGroups([
            $this->item('Catan', ['Age' => 10]),
            $this->item('Hungry Hippos', ['Age' => 2]),
        ], ['by' => 'player_age'], $this->players([2, 6]));

        $this->assertSame(['Age 2', "Older than the players' known ages"], $this->labels($groups));
        $this->assertSame(['Catan'], $this->titles($groups[1]));
    }

    public function test_by_players_ages_adults_take_every_game_too_old_for_the_children(): void
    {
        $groups = $this->planGroups([
            $this->item('Blood on the Clocktower', ['Age' => 12]),
            $this->item('Cards Against Humanity', ['Age' => 21]),
        ], ['by' => 'player_age'], $this->players([18, 6]));

        $this->assertSame(['Adults'], $this->labels($groups));
        $this->assertSame(['Blood on the Clocktower', 'Cards Against Humanity'], $this->titles($groups[0]));
    }

    public function test_by_players_ages_without_any_known_ages_nothing_is_grouped(): void
    {
        $groups = $this->planGroups([$this->item('Catan', ['Age' => 10])], ['by' => 'player_age'], $this->players([null]));

        $this->assertSame(['No player ages recorded'], $this->labels($groups));
    }

    public function test_players_ages_can_be_the_second_grouping(): void
    {
        $groups = $this->planGroups([
            $this->item('Catan', ['Age' => 10, 'tags' => ['main']]),
            $this->item('Outfoxed', ['Age' => 5, 'tags' => ['casual']]),
        ], ['by' => 'tag', 'then' => 'player_age'], $this->players([6, 30]));

        $this->assertSame(['casual', 'main'], $this->labels($groups));
        $this->assertSame(['Age 6'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Adults'], $this->labels($groups[1]['groups']));
    }

    public function test_the_shopping_list_is_the_games_not_kept_in_title_order(): void
    {
        $list = $this->plan([
            $this->item('Wavelength', ['is_kept' => false, 'MnP' => 2, 'MxP' => 12, 'setting' => 'beach', 'note' => 'ask Sam']),
            $this->item('Catan', ['is_kept' => true]),
            $this->item('Blood on the Clocktower', ['is_kept' => false]),
        ])['shopping'];

        $this->assertSame(['Blood on the Clocktower', 'Wavelength'], array_column($list['items'], 'Title'));
        $this->assertArrayNotHasKey('setting', $list['items'][1]);
        // Every line is to buy, so "not kept" would only repeat the heading.
        $this->assertSame("- [ ] Blood on the Clocktower\n- [ ] Wavelength, 2–12, ask Sam\n", $list['text']);
    }

    public function test_the_shopping_list_is_empty_when_every_game_is_kept(): void
    {
        $list = $this->plan([$this->item('Catan', ['is_kept' => true])])['shopping'];

        $this->assertSame(['items' => [], 'text' => ''], $list);
    }

    public function test_the_settings_are_the_events_distinct_settings_in_natural_order(): void
    {
        $plan = $this->plan([
            $this->item('Ra', ['setting' => 'house']),
            $this->item('Inis', ['setting' => ' Beach 10 ']),
            $this->item('Senji', ['setting' => 'beach 9']),
            $this->item('Sky Team', ['setting' => 'house']),
            $this->item('Hive Pocket'),
        ]);

        $this->assertSame(['beach 9', 'Beach 10', 'house'], $plan['settings']);
    }

    /** The plan's smaller list for these items under $grouping. */
    private function spare(array $items, array $grouping, array $players = []): array
    {
        return $this->plan($items, $grouping, $players)['spare'];
    }

    private function spareTitles(array $spare): array
    {
        return array_column($spare['spare'], 'Title');
    }

    public function test_a_game_is_spare_when_every_group_it_is_in_has_enough_without_it(): void
    {
        $spare = $this->spare([
            $this->item('Wavelength', ['SS' => '6']),
            $this->item('Senji', ['SS' => '6']),
            $this->item('Hot Streak', ['SS' => '6']),
            $this->item('Sky Team', ['SS' => '2']),
        ], ['by' => 'players', 'at_least' => 2]);

        // Any one of the three 6-player games can stay home; Sky Team can't.
        $this->assertCount(1, $spare['spare']);
        $this->assertNotSame('Sky Team', $spare['spare'][0]['Title']);
        $this->assertSame([['label' => '2 players', 'count' => 1]], $spare['short']);
    }

    public function test_one_game_covering_several_groups_replaces_two(): void
    {
        // Wavelength alone covers both counts, so Senji and Ra can stay home.
        $spare = $this->spare([
            $this->item('Wavelength', ['SS' => '4,6']),
            $this->item('Senji', ['SS' => '6']),
            $this->item('Ra', ['SS' => '4']),
        ], ['by' => 'players', 'at_least' => 1]);

        $this->assertSame(['Wavelength'], array_column($spare['needed'], 'Title'));
        $this->assertSame(['Ra', 'Senji'], $this->spareTitles($spare));
    }

    public function test_groups_are_each_player_count_and_chosen_tag_together(): void
    {
        $spare = $this->spare([
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['casual']]),
            $this->item('Just One', ['SS' => '6', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['main']]),
            $this->item('Hot Streak', ['SS' => '6', 'tags' => ['beach-safe']]),
        ], ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main', 'at_least' => 1]);

        // Hot Streak has no chosen tag, so it counts toward no group.
        $this->assertCount(2, $spare['needed']);
        $this->assertContains('Senji', array_column($spare['needed'], 'Title'));
        $this->assertContains('Hot Streak', $this->spareTitles($spare));
    }

    public function test_a_group_with_too_few_games_needs_all_of_them_and_says_so(): void
    {
        $spare = $this->spare([
            $this->item('Wavelength', ['SS' => '8', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['main']]),
            $this->item('Inis', ['SS' => '6', 'tags' => ['main']]),
            $this->item('Ra', ['SS' => '6', 'tags' => ['main']]),
        ], ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main', 'at_least' => 2]);

        $this->assertSame([['label' => '8 players · casual', 'count' => 1]], $spare['short']);
        $this->assertContains('Wavelength', array_column($spare['needed'], 'Title'));
        $this->assertCount(1, $spare['spare']);
    }

    public function test_short_groups_follow_the_plans_group_order(): void
    {
        $spare = $this->spare([
            $this->item('Sky Team', ['SS' => '2']),
            $this->item('Wavelength', ['SS' => '8']),
        ], ['by' => 'players', 'at_least' => 2]);

        $this->assertSame(['8 players', '2 players'], array_column($spare['short'], 'label'));
    }

    public function test_a_kept_game_stays_ahead_of_one_to_buy(): void
    {
        $spare = $this->spare([
            $this->item('Blood on the Clocktower', ['SS' => '8', 'is_kept' => false]),
            $this->item('Werewolf', ['SS' => '8', 'is_kept' => true]),
        ], ['by' => 'players', 'at_least' => 1]);

        $this->assertSame(['Werewolf'], array_column($spare['needed'], 'Title'));
    }

    public function test_the_needed_games_are_no_more_than_the_groups_require(): void
    {
        // Greedy picks Big first (covers 3 counts); pruning keeps no game
        // whose groups are all covered without it.
        $spare = $this->spare([
            $this->item('Big', ['SS' => '2-4']),
            $this->item('Two', ['SS' => '2']),
            $this->item('Three', ['SS' => '3']),
            $this->item('Four', ['SS' => '4']),
        ], ['by' => 'players', 'at_least' => 2]);

        $this->assertSame(['Big', 'Four', 'Three', 'Two'], array_column($spare['needed'], 'Title'));
        $this->assertSame([], $spare['spare']);

        $spare = $this->spare([
            $this->item('A', ['SS' => '2-3']),
            $this->item('B', ['SS' => '3-4']),
            $this->item('C', ['SS' => '2,4']),
            $this->item('D', ['SS' => '2-4']),
        ], ['by' => 'players', 'at_least' => 1]);

        $this->assertSame(['D'], array_column($spare['needed'], 'Title'));
    }

    public function test_without_grouping_the_count_is_for_the_whole_plan(): void
    {
        $spare = $this->spare([
            $this->item('Ra'),
            $this->item('Inis'),
            $this->item('Senji'),
        ], ['by' => 'none', 'at_least' => 2]);

        $this->assertCount(2, $spare['needed']);
        $this->assertCount(1, $spare['spare']);
    }

    public function test_asking_for_no_games_per_group_leaves_nothing_spare(): void
    {
        $spare = $this->spare([$this->item('Ra', ['SS' => '3'])], ['by' => 'players', 'at_least' => 0]);

        $this->assertSame(['Ra'], array_column($spare['needed'], 'Title'));
        $this->assertSame([], $spare['spare']);
        $this->assertSame([], $spare['short']);
        $this->assertSame('', $spare['text']);
    }

    public function test_the_count_per_group_is_a_whole_number_from_zero_to_ninety_nine(): void
    {
        $this->assertSame(2, event_plan_grouping(['at_least' => '2'], [])['at_least']);
        $this->assertSame(0, event_plan_grouping(['at_least' => ''], ['at_least' => 3])['at_least']);
        $this->assertSame(3, event_plan_grouping(['at_least' => 'many'], ['at_least' => 3])['at_least']);
        $this->assertSame(3, event_plan_grouping([], ['at_least' => 3])['at_least']);
        $this->assertSame(0, event_plan_grouping(['at_least' => '-1'], [])['at_least']);
    }

    public function test_the_count_can_hold_for_only_some_of_the_chosen_tags(): void
    {
        $spare = $this->spare([
            $this->item('Wavelength', ['SS' => '6', 'tags' => ['casual']]),
            $this->item('Senji', ['SS' => '6', 'tags' => ['main']]),
            $this->item('Outfoxed', ['SS' => '6', 'tags' => ['kids']]),
        ], ['by' => 'players', 'then' => 'tag', 'tags' => 'casual, main, kids', 'at_least' => 1, 'count_tags' => 'Main, casual']);

        $this->assertSame(['Outfoxed'], $this->spareTitles($spare));
    }

    public function test_the_tags_the_count_holds_for_are_saved_with_the_grouping(): void
    {
        $this->assertSame('casual, main', event_plan_grouping(['count_tags' => 'Casual,main'], [])['count_tags']);
        $this->assertSame('main', event_plan_grouping([], ['count_tags' => 'main'])['count_tags']);
    }

    public function test_the_smaller_lists_text_is_the_needed_games_under_the_same_grouping(): void
    {
        $items = [
            $this->item('Catan', ['SS' => '4', 'Age' => 10, 'tags' => ['main']]),
            $this->item('Ra', ['SS' => '4', 'Age' => 12, 'tags' => ['main']]),
            $this->item('Outfoxed', ['SS' => '4', 'Age' => 5, 'tags' => ['casual']]),
            $this->item('Sushi Go', ['SS' => '4', 'Age' => 6, 'tags' => ['casual']]),
            $this->item('Hive Pocket', ['SS' => '2', 'tags' => ['beach-safe']]),
        ];
        $grouping = ['by' => 'player_age', 'then' => 'tag', 'tags' => 'casual, main'];
        $players = $this->players([6, 40]);
        $spare = $this->spare($items, $grouping + ['at_least' => 1], $players);
        $needed = array_values(array_filter($items, fn($item) => in_array($item['Title'], array_column($spare['needed'], 'Title'), true)));

        $this->assertNotSame([], $spare['spare']);
        $this->assertSame($this->plan($needed, $grouping, $players)['text'], $spare['text']);
    }

    public function test_every_item_row_says_whether_it_can_stay_home(): void
    {
        $plan = $this->plan([
            $this->item('Blood on the Clocktower', ['SS' => '8', 'is_kept' => false]),
            $this->item('Werewolf', ['SS' => '8', 'is_kept' => true]),
        ], ['by' => 'players', 'at_least' => 1]);

        $this->assertSame([true], array_column($plan['shopping']['items'], 'can_stay_home'));
        $this->assertSame([true, false], array_column($plan['groups'][0]['items'], 'can_stay_home'));
        $this->assertSame([true], array_column($plan['spare']['spare'], 'can_stay_home'));
        $this->assertSame([false], array_column($plan['spare']['needed'], 'can_stay_home'));
    }

    public function test_no_item_can_stay_home_without_a_count_per_group(): void
    {
        $plan = $this->plan([
            $this->item('Blood on the Clocktower', ['SS' => '8', 'is_kept' => false]),
            $this->item('Werewolf', ['SS' => '8', 'is_kept' => true]),
        ], ['by' => 'players', 'then' => 'age', 'at_least' => 0]);

        $this->assertSame([false], array_column($plan['shopping']['items'], 'can_stay_home'));
        $this->assertSame([false, false], array_column($plan['groups'][0]['groups'][0]['items'], 'can_stay_home'));
        $this->assertSame([false, false], array_column($plan['spare']['needed'], 'can_stay_home'));
    }
}
