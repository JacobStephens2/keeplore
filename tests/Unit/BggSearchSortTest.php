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

    public function test_page_marks_every_column_sortable_and_loads_the_sort(): void
    {
        $page = file_get_contents(PROJECT_PATH . '/ui/bgg-search/index.php');
        foreach (['name', 'best', 'votes', 'age', 'rank', 'average', 'kept'] as $key) {
            $this->assertStringContainsString('data-sort="' . $key . '"', $page);
        }
        $this->assertStringContainsString('/shared/js/list-table.js', $page);
        $this->assertStringContainsString('/bgg-search/bgg-search.js', $page);
        // Sorting by Best uses the lowest Best count ("6-7" sorts as 6); a
        // game with no Best vote leaves it blank so it sorts last.
        $this->assertStringContainsString("\$game['best_players'] === '' ? '' : (int) \$game['best_players']", $page);
    }

    public function test_average_column_uses_the_bgg_average_spelling(): void
    {
        $this->assertSame('8', bgg_overall_rating_text('8.00'));
        $this->assertSame('7.09', bgg_overall_rating_text(7.09));
        $this->assertNull(bgg_overall_rating_text(''));
        $this->assertNull(bgg_overall_rating_text(null));
        $this->assertNull(bgg_overall_rating_text(0));
        $this->assertNull(bgg_overall_rating_text(11));

        $page = (string) file_get_contents(PROJECT_PATH . '/ui/bgg-search/index.php');
        $this->assertStringContainsString("bgg_overall_rating_text(\$game['average'])", $page);
        $this->assertStringNotContainsString("number_format(\$game['average'], 2)", $page);
        $this->assertStringContainsString("data-average=\"<?php echo h((string) \$game['average']); ?>\"", $page);
    }
}
