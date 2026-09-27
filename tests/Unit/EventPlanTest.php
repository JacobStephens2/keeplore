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

    public function test_player_counts_can_be_sub_grouped_by_tag(): void
    {
        $groups = event_plan_groups([
            $this->item('Guards of Atlantis II', ['SS' => '4,6,8', 'tags' => ['strategy']]),
            $this->item('Wavelength', ['SS' => '6,8', 'tags' => ['casual']]),
        ], 'players', 'tag');

        $this->assertSame(['8 players', '6 players', '4 players'], $this->labels($groups));
        $this->assertSame(['casual', 'strategy'], $this->labels($groups[0]['groups']));
        $this->assertSame(['Wavelength'], $this->titles($groups[0]['groups'][0]));
        $this->assertSame(['strategy'], $this->labels($groups[2]['groups']));
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
            . "## Untagged\n"
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
}
