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
        $this->assertSame('', $filters['sweetSpotFilter']);
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
        $this->assertSame('3', $filters['sweetSpotFilter']);
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
}
