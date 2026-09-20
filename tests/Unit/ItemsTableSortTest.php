<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * /artifacts/ must keep the column-header sort the user chose, including
 * across reloads and after a column such as Tags is inserted.
 */
class ItemsTableSortTest extends TestCase
{
    public function test_named_column_sort_persists_across_reload_and_inserted_columns(): void
    {
        // PHPUnit has no JS runner; this is the suite hook for the node tests.
        $script = PROJECT_PATH . '/tests/Unit/items-table-sort.test.js';
        $cmd = 'node --test ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_items_page_restores_tracking_start_sort_from_column_names(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'items-table-sort.js',
            $source,
            'Items page must load the named-column sort module.'
        );
        $this->assertMatchesRegularExpression(
            "/column:\\s*['\"]Tracking Start['\"]/",
            $source,
            'Fresh visits must fall back to Tracking Start, not Name.'
        );
        $this->assertStringContainsString(
            'KeeploreItemsTableSort.restore(',
            $source,
            'DataTable init must restore the persisted column-header order.'
        );
        $this->assertStringContainsString(
            'KeeploreItemsTableSort.persist(',
            $source,
            'Column-header clicks must persist the named sort for the next visit.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/new DataTable\\('#artifacts',[\\s\\S]*?order:\\s*\\[\\s*\\[\\s*3,/",
            $source,
            'Do not hardcode order column 3; after Tags that is Name and puts Zork I first.'
        );
    }
}
