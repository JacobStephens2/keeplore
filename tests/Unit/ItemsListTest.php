<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Browser sorting, markup and shared styling that survive the page refactor. */
class ItemsListTest extends TestCase
{
    public function test_items_page_search_is_in_the_document_and_autofocused(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="items-search"[^>]*autofocus/i',
            $source,
            'The items search box must be in the page HTML with autofocus so typing does not wait for the list.'
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

    public function test_items_sort_by_the_values_on_the_row(): void
    {
        // PHPUnit has no JS runner; this is the suite hook for the node tests.
        $script = PROJECT_PATH . '/tests/Unit/items-list.test.js';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_items_page_draws_headers_and_cells_from_the_one_column_list(): void
    {
        $page = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $js = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');

        $this->assertStringContainsString("'columns' =>", $page);
        $this->assertStringContainsString('config.columns', $js);
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
    }

    public function test_items_page_offers_an_include_unknown_ages_checkbox(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="age_unknown" value="yes"/', $source);
    }

    public function test_items_page_renders_the_type_switch(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertMatchesRegularExpression('/<nav class="kept-switch type-switch" aria-label="Item type">/', $source);
    }

    public function test_each_items_row_offers_a_copy_button(): void
    {
        $js = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');
        $this->assertStringContainsString('item.copy_text', $js);
        $this->assertStringContainsString('navigator.clipboard.writeText', $js);
        $this->assertStringContainsString("'copy-item-btn'", $js);
    }

    public function test_the_rating_dialog_stays_fixed_so_opening_it_keeps_the_scroll_position(): void
    {
        // .modal-panel (position: relative) comes later in the file; a modal
        // dialog left relative computes to absolute, opens at the top of the
        // document and focusing it scrolls the page there.
        $css = (string) file_get_contents(PROJECT_PATH . '/ui/style.css');
        $this->assertSame(1, preg_match('/\.modal-panel\.bgg-rating-dialog\s*\{([^}]*)\}/', $css, $rule));
        $this->assertStringContainsString('position: fixed', $rule[1]);
    }

    public function test_edit_item_shows_the_play_facts_right_under_its_heading(): void
    {
        $edit = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $this->assertMatchesRegularExpression(
            '/<h1>Edit <\?php echo h\(\$artifact\[\'Title\'\]\); \?><\/h1>\s*<\?php \$play_facts = /',
            $edit
        );
        $this->assertStringContainsString('class="item-play-facts"', $edit);
        // Blank player fields save as 5-240 on Edit Item, so a book would
        // headline "5–240 players"; the line is for games only.
        $this->assertStringContainsString("item_type_is_game(\$artifact['type'] ?? '')", $edit);
    }

    public function test_the_play_facts_rule_does_not_swallow_the_picture_selectors(): void
    {
        $css = (string) file_get_contents(PROJECT_PATH . '/ui/style.css');
        $this->assertMatchesRegularExpression('/\.bgg-match-image,\s*\.item-picture-preview,\s*\.item-picture\s*\{/', $css);
    }

    public function test_items_page_offers_a_youngest_age_picker_beside_the_count(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertMatchesRegularExpression('/<input type="number" name="age"/', $source);
    }

    public function test_browser_uses_presented_rating_labels(): void
    {
        $js = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/items-list.js');
        $this->assertStringContainsString('rating.score_text', $js);
        $this->assertStringContainsString('rating.site_name', $js);
        $this->assertStringNotContainsString('String(Number(rating.rating))', $js);
        $this->assertStringContainsString("'Comment'", $js);
        $this->assertStringContainsString('Commented without a rating', $js);
        $this->assertStringContainsString('(entered by hand)', $js);
    }
}
