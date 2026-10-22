<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Search BGG's results sort by clicking a column header.
 */
class BggSearchSortTest extends TestCase
{
    public function test_results_sort_by_each_column(): void
    {
        // PHPUnit has no JS runner; this is the suite hook for the node tests.
        $script = PROJECT_PATH . '/tests/Unit/bgg-search.test.js';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_page_prints_the_row_and_does_not_encode_sort_values_on_it(): void
    {
        $page = file_get_contents(PROJECT_PATH . '/ui/bgg-search/index.php');
        foreach (['name', 'best', 'votes', 'age', 'rank', 'average', 'kept'] as $key) {
            $this->assertStringContainsString('data-sort="' . $key . '"', $page);
        }
        $this->assertStringContainsString('/shared/js/list-table.js', $page);
        $this->assertStringContainsString('/bgg-search/bgg-search.js', $page);
        $this->assertStringContainsString('bgg_search_results(', $page);
        $this->assertStringContainsString('type="application/json" id="bgg-search-rows"', $page);
        foreach (['best_text', 'votes_text', 'age_text', 'rank_text', 'average_text', 'kept_text'] as $field) {
            $this->assertStringContainsString("h(\$row['" . $field . "'])", $page);
        }
        $this->assertStringContainsString("h(\$review['text'])", $page);
        $this->assertStringContainsString("h(\$review['comment'])", $page);
        foreach (['data-name', 'data-best', 'data-votes', 'data-age', 'data-rank', 'data-average', 'data-kept', 'data-ratings'] as $attribute) {
            $this->assertStringNotContainsString($attribute, $page);
        }
    }
}
