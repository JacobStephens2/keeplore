<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';
require_once PROJECT_PATH . '/private/items_list.php';

/**
 * Seams:
 * - items_list_present_row(): display record for one item on /artifacts/
 * - items_list_filters_from_request(): kept/type/interval/tag state for the page
 * - ui/artifacts/index.php source: search is in the first HTML, independent
 *   of the items query and of DataTables; column order is Kept then Name
 */
class ItemsListTest extends TestCase
{
    public function test_never_used_item_use_by_is_acquisition_plus_interval(): void
    {
        $row = items_list_present_row([
            'id' => 10,
            'Title' => 'Catan',
            'type' => 'table-game',
            'tags' => ['beach-safe'],
            'is_kept' => 1,
            'Acq' => '2024-01-10',
            'MaxPlay' => null,
            'MaxUse' => null,
            'ss' => '3-4',
            'mnt' => 45,
            'mxt' => 75,
            'Candidate' => '',
        ], 90, '2024-06-01');

        $this->assertSame(10, $row['id']);
        $this->assertSame('Catan', $row['title']);
        $this->assertSame('table-game', $row['type']);
        $this->assertSame(['beach-safe'], $row['tags']);
        $this->assertTrue($row['is_kept']);
        $this->assertSame('2024-01-10', $row['acq']);
        $this->assertSame('', $row['most_recent_use']);
        $this->assertSame('2024-04-09', $row['use_by']);
        $this->assertTrue($row['use_by_overdue']);
        $this->assertSame('3-4', $row['ss']);
        $this->assertSame(60, $row['avg_time']);
        $this->assertFalse($row['candidate']);
    }

    public function test_recent_use_doubles_the_interval_and_prefers_the_later_date(): void
    {
        $row = items_list_present_row([
            'id' => 11,
            'Title' => 'Carcassonne',
            'type' => 'table-game',
            'tags' => [],
            'is_kept' => 1,
            'Acq' => '2020-01-01',
            'MaxPlay' => '2024-01-01',
            'MaxUse' => '2024-03-01',
            'ss' => '',
            'mnt' => 20,
            'mxt' => 21,
            'Candidate' => '1',
        ], 90, '2024-06-01');

        $this->assertSame('2024-03-01', $row['most_recent_use']);
        $this->assertSame('2024-08-28', $row['use_by']);
        $this->assertFalse($row['use_by_overdue']);
        $this->assertTrue($row['candidate']);
        $this->assertSame(21, $row['avg_time']);
    }

    public function test_unkept_item_is_never_overdue(): void
    {
        $row = items_list_present_row([
            'id' => 12,
            'Title' => 'Old Game',
            'type' => 'table-game',
            'tags' => [],
            'is_kept' => 0,
            'Acq' => '2020-01-01',
            'MaxPlay' => null,
            'MaxUse' => null,
            'ss' => '',
            'mnt' => 0,
            'mxt' => 0,
            'Candidate' => 0,
        ], 90, '2024-06-01');

        $this->assertFalse($row['is_kept']);
        $this->assertFalse($row['use_by_overdue']);
    }

    public function test_invalid_acquisition_date_hides_use_by(): void
    {
        $row = items_list_present_row([
            'id' => 13,
            'Title' => 'Broken Date',
            'type' => '',
            'tags' => [],
            'is_kept' => 1,
            'Acq' => 'not-a-date',
            'MaxPlay' => null,
            'MaxUse' => null,
            'ss' => '',
            'mnt' => 0,
            'mxt' => 0,
            'Candidate' => '',
        ], 90, '2024-06-01');

        $this->assertSame('', $row['use_by']);
        $this->assertFalse($row['use_by_overdue']);
    }

    public function test_get_kept_all_alias_and_default_types(): void
    {
        $filters = items_list_filters_from_request(
            ['kept' => 'all'],
            [],
            'GET',
            90,
            ['table-game' => '4', 'book' => '7']
        );

        $this->assertSame('allkeptandnot', $filters['kept']);
        $this->assertSame(['table-game' => '4', 'book' => '7'], $filters['type']);
        $this->assertSame(90, $filters['interval']);
        $this->assertNull($filters['players']);
        $this->assertSame('no', $filters['showAttributes']);
        $this->assertSame('', $filters['tagFilter']);
    }

    public function test_post_filters_override_get_and_preserve_tag(): void
    {
        $filters = items_list_filters_from_request(
            ['kept' => 'yes', 'tag' => 'ignored'],
            [
                'kept' => 'no',
                'type' => ['4' => '4'],
                'interval' => '30',
                'sweetSpotFilter' => '3',
                'showAttributes' => 'yes',
                'tag' => 'beach-safe',
            ],
            'POST',
            90,
            ['table-game' => '4', 'book' => '7']
        );

        $this->assertSame('no', $filters['kept']);
        $this->assertSame(['4' => '4'], $filters['type']);
        $this->assertSame(30, $filters['interval']);
        $this->assertSame(3, $filters['players']);
        $this->assertSame('yes', $filters['showAttributes']);
        $this->assertSame('beach-safe', $filters['tagFilter']);
    }

    public function test_items_page_search_is_in_the_document_and_autofocused(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="items-search"[^>]*autofocus/i',
            $source,
            'The items search box must be in the page HTML with autofocus so typing does not wait for the list.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/find_artifacts_by_user_id\s*\(/',
            $source,
            'The items page must not query the collection before sending the search box.'
        );
        $this->assertStringNotContainsString(
            'dataTable.html',
            $source,
            'Search must not be created by DataTables after the table is ready.'
        );
        $this->assertStringContainsString('/shared/js/list-table.js', $source);
        $this->assertStringContainsString('/shared/js/items-list.js', $source);
        $this->assertStringContainsString('/artifacts/items-data.php', $source);
        $this->assertStringContainsString("event.key !== 'n'", $source);
        $js = file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');
        $this->assertNotFalse($js);
        $this->assertStringContainsString('KeeploreItemsTableSort.restore(', $js);
    }

    public function test_item_name_is_the_second_column_after_kept(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<th data-sort="is_kept">Kept<\/th>\s*<th data-sort="title" id="items-name-header">Name<\/th>/',
            $source,
            'Name must be the second column, immediately after Kept.'
        );

        $js = file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');
        $this->assertNotFalse($js);
        $this->assertMatchesRegularExpression(
            '/var cells = \[\s*renderKeptCell\(item, config\),\s*el\(\'td\', \{ className: \'artifact_title\' \}/',
            $js,
            'Each item row must put the name cell immediately after Kept.'
        );
    }

    private function extractCssRuleBlock(string $css, string $selector): string
    {
        $pattern = '/' . preg_quote($selector, '/') . '[^\{]*\{(.*?)\}/s';
        $this->assertSame(1, preg_match($pattern, $css, $match), "CSS rule for {$selector} must exist.");
        return $match[1];
    }

    public function test_kept_and_keep_buttons_have_distinct_color_styles(): void
    {
        $css = (string) file_get_contents(PROJECT_PATH . '/ui/style.css');
        $this->assertNotFalse($css);

        $keptRule = $this->extractCssRuleBlock($css, '.kept-toggle-btn[aria-pressed="true"]');
        $keepRule = $this->extractCssRuleBlock($css, '.kept-toggle-btn[aria-pressed="false"]');

        // Kept button styling (green / success)
        $this->assertStringContainsString('var(--success)', $keptRule, 'Kept button must use var(--success).');
        $this->assertStringContainsString('var(--on-primary)', $keptRule, 'Kept button must use var(--on-primary) text.');

        // Keep button styling (ghost / outline / unkept)
        $this->assertStringContainsString('transparent', $keepRule, 'Keep button must have transparent ghost background.');
        $this->assertStringContainsString('var(--primary)', $keepRule, 'Keep button must use var(--primary) text.');
        $this->assertStringContainsString('var(--outline-strong)', $keepRule, 'Keep button must use var(--outline-strong) border.');

        $this->assertNotEquals($keptRule, $keepRule, 'Kept and Keep buttons must have different styles.');
    }

    public function test_items_list_js_maintains_toggle_state_attributes(): void
    {
        $js = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');
        $this->assertNotFalse($js);

        // Renders aria-pressed based on is_kept
        $this->assertMatchesRegularExpression(
            "/className:\s*'kept-toggle-btn'/",
            $js,
            'Button must have kept-toggle-btn class.'
        );
        $this->assertMatchesRegularExpression(
            "/'aria-pressed':\s*item\.is_kept\s*\?\s*'true'\s*:\s*'false'/",
            $js,
            'Button must initialize aria-pressed attribute based on item.is_kept.'
        );

        // Updates aria-pressed and text on toggle
        $this->assertMatchesRegularExpression(
            "/button\.setAttribute\(\s*'aria-pressed',\s*isKept\s*\?\s*'true'\s*:\s*'false'\s*\)/",
            $js,
            'Button must update aria-pressed attribute when toggle response is received.'
        );
        $this->assertMatchesRegularExpression(
            "/button\.textContent\s*=\s*isKept\s*\?\s*'Kept'\s*:\s*'Keep'/",
            $js,
            'Button must update textContent between Kept and Keep on toggle.'
        );
    }
    /**
     * @dataProvider sweetSpotSpellings
     */
    public function test_sweet_spot_counts_read_every_stored_spelling(string $ss, array $counts): void
    {
        $this->assertSame($counts, items_list_sweet_spot_counts($ss));
    }

    public static function sweetSpotSpellings(): array
    {
        return [
            'blank' => ['', []],
            'bgg zero padded' => ['01', [1]],
            'unpadded' => ['1', [1]],
            'padded list' => ['03,04', [3, 4]],
            'spaced list' => ['01, 2, 3, 4', [1, 2, 3, 4]],
            'range' => ['06-8', [6, 7, 8]],
            'spaced range' => ['3 - 5', [3, 4, 5]],
            'runaway range capped' => ['98-2000000000', [98, 99]],
            'range and count' => ['03-4, 6', [3, 4, 6]],
            'trailing tab' => ["02,3\t", [2, 3]],
            'out of order' => ['10, 5, 1', [1, 5, 10]],
            'wide range' => ['03,10-12', [3, 10, 11, 12]],
        ];
    }

    public function test_players_label_is_the_range_with_its_sweet_spot(): void
    {
        $this->assertSame('2–4 (best 3)', items_list_players_label(2, 4, '03'));
        $this->assertSame('3–6 (best 3–5)', items_list_players_label(3, 6, '03-5'));
        $this->assertSame('1–8 (best 3, 4, 6)', items_list_players_label(1, 8, '03,04,06'));
        $this->assertSame('2–4', items_list_players_label(2, 4, ''));
        $this->assertSame('2 (best 2)', items_list_players_label(2, 2, '02'));
        $this->assertSame('best 3', items_list_players_label(null, null, '03'));
        $this->assertSame('', items_list_players_label(null, null, null));
    }

    public function test_present_row_carries_the_players_label(): void
    {
        $row = items_list_present_row([
            'id' => 1,
            'Title' => 'Azul',
            'Acq' => '2024-01-10',
            'ss' => '02',
            'mnp' => 2,
            'mxp' => 4,
        ], 90, '2024-06-01');

        $this->assertSame('2–4 (best 2)', $row['players']);
    }

    public function test_players_count_comes_from_players_or_the_legacy_sweet_spot_parameter(): void
    {
        $types = ['table-game' => '4'];
        $this->assertSame(3, items_list_filters_from_request(['players' => '3'], [], 'GET', 90, $types)['players']);
        $this->assertSame(5, items_list_filters_from_request(['sweetSpotFilter' => '5'], [], 'GET', 90, $types)['players']);
        $this->assertNull(items_list_filters_from_request(['players' => ''], [], 'GET', 90, $types)['players']);
        $this->assertNull(items_list_filters_from_request(['players' => '0'], [], 'GET', 90, $types)['players']);
        $this->assertNull(items_list_filters_from_request(['players' => 'three'], [], 'GET', 90, $types)['players']);
    }

    public function test_query_params_carry_the_players_count(): void
    {
        $filters = items_list_filters_from_request(['players' => '3'], [], 'GET', 90, ['table-game' => '4']);
        $params = items_list_query_params($filters, ['table-game' => '4']);

        $this->assertSame(3, $params['players']);
        $this->assertArrayNotHasKey('sweetSpotFilter', $params);
    }

    public function test_best_at_keeps_only_items_whose_sweet_spot_holds_the_count(): void
    {
        $rows = [
            ['id' => 1, 'ss' => '03'],
            ['id' => 2, 'ss' => '06-8'],
            ['id' => 3, 'ss' => '13'],
            ['id' => 4, 'ss' => ''],
            ['id' => 5, 'ss' => '02, 3, 4'],
        ];

        $this->assertSame([1, 5], array_column(items_list_best_at($rows, 3), 'id'));
        $this->assertSame([2], array_column(items_list_best_at($rows, 7), 'id'));
        $this->assertSame([1, 2, 3, 4, 5], array_column(items_list_best_at($rows, null), 'id'));
    }

    public function test_items_page_titles_a_chosen_count_best_at(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertStringContainsString("items_list_heading(\$players, \$age)", $source);
        $this->assertSame('Best at 3 players', items_list_heading(3, null));
        $this->assertSame('Best at 1 player', items_list_heading(1, null));
        $this->assertNull(items_list_heading(null, null));
        $this->assertMatchesRegularExpression('/<form class="player-picker" method="get"/', $source);
    }

    public function test_items_page_titles_a_chosen_age_and_both_together(): void
    {
        $this->assertSame('Suitable for age 7', items_list_heading(null, 7));
        $this->assertSame('Best at 3 players, suitable for age 2', items_list_heading(3, 2));
    }

    public function test_age_comes_from_the_age_parameter_as_a_positive_whole_number(): void
    {
        $types = ['table-game' => '4'];
        $this->assertSame(7, items_list_filters_from_request(['age' => '7'], [], 'GET', 90, $types)['age']);
        $this->assertSame(2, items_list_filters_from_request([], ['age' => ' 2 '], 'POST', 90, $types)['age']);
        $this->assertNull(items_list_filters_from_request([], [], 'GET', 90, $types)['age']);
        $this->assertNull(items_list_filters_from_request(['age' => ''], [], 'GET', 90, $types)['age']);
        $this->assertNull(items_list_filters_from_request(['age' => '0'], [], 'GET', 90, $types)['age']);
        $this->assertNull(items_list_filters_from_request(['age' => 'seven'], [], 'GET', 90, $types)['age']);
    }

    public function test_query_params_carry_the_age_only_when_chosen(): void
    {
        $types = ['table-game' => '4'];
        $with = items_list_query_params(items_list_filters_from_request(['age' => '7'], [], 'GET', 90, $types), $types);
        $without = items_list_query_params(items_list_filters_from_request([], [], 'GET', 90, $types), $types);

        $this->assertSame(7, $with['age']);
        $this->assertArrayNotHasKey('age', $without);
    }

    public function test_suitable_for_age_keeps_items_whose_minimum_age_is_known_and_at_most_the_age(): void
    {
        $rows = [
            ['id' => 1, 'Age' => 8],
            ['id' => 2, 'Age' => 7],
            ['id' => 3, 'Age' => 2],
            ['id' => 4, 'Age' => 0],
            ['id' => 5, 'Age' => null],
            ['id' => 6],
            ['id' => 7, 'age' => '3'],
        ];

        $this->assertSame([2, 3, 7], array_column(items_list_suitable_for_age($rows, 7), 'id'));
        $this->assertSame([3], array_column(items_list_suitable_for_age($rows, 2), 'id'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column(items_list_suitable_for_age($rows, null), 'id'));
    }

    public function test_include_unknown_ages_comes_from_age_unknown_yes(): void
    {
        $types = ['table-game' => '4'];
        $this->assertTrue(items_list_filters_from_request(['age' => '2', 'age_unknown' => 'yes'], [], 'GET', 90, $types)['ageUnknown']);
        $this->assertTrue(items_list_filters_from_request([], ['age' => '2', 'age_unknown' => 'yes'], 'POST', 90, $types)['ageUnknown']);
        $this->assertFalse(items_list_filters_from_request(['age' => '2', 'age_unknown' => 'no'], [], 'GET', 90, $types)['ageUnknown']);
        $this->assertFalse(items_list_filters_from_request(['age' => '2'], [], 'GET', 90, $types)['ageUnknown']);
    }

    public function test_query_params_carry_include_unknown_ages_only_with_an_age(): void
    {
        $types = ['table-game' => '4'];
        $with = items_list_query_params(items_list_filters_from_request(['age' => '2', 'age_unknown' => 'yes'], [], 'GET', 90, $types), $types);
        $no_age = items_list_query_params(items_list_filters_from_request(['age_unknown' => 'yes'], [], 'GET', 90, $types), $types);
        $off = items_list_query_params(items_list_filters_from_request(['age' => '2'], [], 'GET', 90, $types), $types);

        $this->assertSame('yes', $with['age_unknown']);
        $this->assertArrayNotHasKey('age_unknown', $no_age);
        $this->assertArrayNotHasKey('age_unknown', $off);
    }

    public function test_suitable_for_age_can_include_items_with_no_recorded_age(): void
    {
        $rows = [
            ['id' => 1, 'Age' => 8],
            ['id' => 2, 'Age' => 2],
            ['id' => 3, 'Age' => 0],
            ['id' => 4, 'Age' => null],
            ['id' => 5],
        ];

        $this->assertSame([2, 3, 4, 5], array_column(items_list_suitable_for_age($rows, 2, true), 'id'));
        $this->assertSame([2], array_column(items_list_suitable_for_age($rows, 2, false), 'id'));
        $this->assertSame([1, 2, 3, 4, 5], array_column(items_list_suitable_for_age($rows, null, true), 'id'));
    }

    public function test_items_page_offers_an_include_unknown_ages_checkbox(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="age_unknown" value="yes"/', $source);
    }

    public function test_present_row_carries_the_minimum_age_label(): void
    {
        $base = ['id' => 1, 'Title' => 'Azul', 'Acq' => '2024-01-10'];

        $this->assertSame('8+', items_list_present_row($base + ['Age' => 8], 90, '2024-06-01')['age']);
        $this->assertSame('', items_list_present_row($base + ['Age' => 0], 90, '2024-06-01')['age']);
        $this->assertSame('', items_list_present_row($base, 90, '2024-06-01')['age']);
    }

    public function test_type_switch_offers_all_games_and_other(): void
    {
        $types = ['book' => '4', 'card game' => '81', 'other' => '44', 'table game' => '26'];

        $switch = items_list_type_switch($types, ['4', '81', '44', '26']);

        $this->assertSame(['all', 'games', 'other'], array_keys($switch['options']));
        $this->assertSame(['4', '81', '44', '26'], $switch['options']['all']['type_ids']);
        $this->assertSame(['81', '26'], $switch['options']['games']['type_ids']);
        $this->assertSame(['44'], $switch['options']['other']['type_ids']);
        $this->assertSame('All types', $switch['options']['all']['label']);
        $this->assertSame('Games', $switch['options']['games']['label']);
        $this->assertSame('Other', $switch['options']['other']['label']);
        $this->assertSame('all', $switch['active']);
    }

    public function test_type_switch_marks_the_matching_selection_active_in_any_order(): void
    {
        $types = ['book' => '4', 'card game' => '81', 'other' => '44', 'table game' => '26'];

        $this->assertSame('games', items_list_type_switch($types, [26, '81'])['active']);
        $this->assertSame('other', items_list_type_switch($types, ['44'])['active']);
        $this->assertNull(items_list_type_switch($types, ['4'])['active']);
        $this->assertSame('all', items_list_type_switch($types, [])['active']);
    }

    public function test_type_switch_leaves_out_a_choice_the_user_has_no_types_for(): void
    {
        $switch = items_list_type_switch(['book' => '4', 'Other' => '9'], ['4', '9']);

        $this->assertSame(['all', 'other'], array_keys($switch['options']));
        $this->assertSame(['9'], $switch['options']['other']['type_ids']);
    }

    public function test_items_page_renders_the_type_switch(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertStringContainsString('items_list_type_switch(', $source);
        $this->assertMatchesRegularExpression('/<nav class="kept-switch type-switch" aria-label="Item type">/', $source);
    }

    public function test_items_page_offers_a_youngest_age_picker_beside_the_count(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertMatchesRegularExpression('/<input type="number" name="age"/', $source);
        $this->assertStringContainsString("'showAge' =>", $source);
    }
}
