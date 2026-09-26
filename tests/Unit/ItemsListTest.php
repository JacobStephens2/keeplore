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
        $this->assertStringContainsString("items_list_best_at_heading(\$players)", $source);
        $this->assertSame('Best at 3 players', items_list_best_at_heading(3));
        $this->assertSame('Best at 1 player', items_list_best_at_heading(1));
        $this->assertNull(items_list_best_at_heading(null));
        $this->assertMatchesRegularExpression('/<form class="player-picker" method="get"/', $source);
    }
}
