<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/interact_by.php';

/**
 * Seams, the Interact By page module's interface:
 * - interact_by_filters_from_request(): the page's filters, remembering hide snoozed
 * - interact_by_queue_options(): those filters as UseByQueue::entries options
 * - interact_by_present_row(): one Use-by queue entry as a table row
 * - interact_by_table(): the table's column keys and DataTable order
 */
class InteractByTest extends TestCase
{
    public function test_a_get_shows_the_defaults_and_hides_snoozed_items(): void
    {
        $session = [];
        $filters = interact_by_filters_from_request('GET', [], $session, 90);

        $this->assertSame([
            'sweetSpot' => '',
            'minimumAge' => 0,
            'shelfSort' => 'no',
            'showAttributes' => 'no',
            'showInterval' => 'no',
            'hideSnoozed' => 'yes',
            'interval' => 90,
        ], $filters);
        $this->assertSame('yes', $session['hideSnoozed']);
    }

    public function test_a_post_reads_the_filters_and_normalises_each_yes_or_no(): void
    {
        $session = [];
        $filters = interact_by_filters_from_request('POST', [
            'sweetSpot' => '3',
            'minimumAge' => '8',
            'shelfSort' => 'yes',
            'showAttributes' => 'yes',
            'showInterval' => 'on',
            'hideSnoozed' => 'no',
            'interval' => '30.5',
        ], $session, 90);

        $this->assertSame('3', $filters['sweetSpot']);
        $this->assertSame('8', $filters['minimumAge']);
        $this->assertSame('yes', $filters['shelfSort']);
        $this->assertSame('yes', $filters['showAttributes']);
        $this->assertSame('no', $filters['showInterval']);
        $this->assertSame('no', $filters['hideSnoozed']);
        $this->assertSame(30.5, $filters['interval']);
    }

    public function test_hide_snoozed_is_remembered_from_a_post_for_later_gets(): void
    {
        $session = [];
        interact_by_filters_from_request('POST', ['hideSnoozed' => 'no'], $session, 90);
        $this->assertSame('no', $session['hideSnoozed']);

        $this->assertSame('no', interact_by_filters_from_request('GET', [], $session, 90)['hideSnoozed']);

        interact_by_filters_from_request('POST', ['hideSnoozed' => 'yes'], $session, 90);
        $this->assertSame('yes', interact_by_filters_from_request('GET', [], $session, 90)['hideSnoozed']);
    }

    public function test_a_post_without_the_hide_snoozed_box_shows_snoozed_items(): void
    {
        $session = ['hideSnoozed' => 'yes'];
        $this->assertSame('no', interact_by_filters_from_request('POST', [], $session, 90)['hideSnoozed']);
        $this->assertSame('no', $session['hideSnoozed']);
    }

    public function test_a_remembered_hide_snoozed_that_is_not_yes_reads_as_no(): void
    {
        $session = ['hideSnoozed' => 'maybe'];
        $this->assertSame('no', interact_by_filters_from_request('GET', [], $session, 90)['hideSnoozed']);
    }

    public function test_a_get_ignores_a_posted_interval(): void
    {
        $session = [];
        $this->assertSame(90, interact_by_filters_from_request('GET', ['interval' => '14'], $session, 90)['interval']);
    }

    public function test_an_interval_that_is_not_numeric_falls_back_to_the_default(): void
    {
        $session = [];
        $this->assertSame(90, interact_by_filters_from_request('POST', ['interval' => ''], $session, 90)['interval']);
        $this->assertSame(90, interact_by_filters_from_request('POST', ['interval' => 'soon'], $session, 90)['interval']);
        $this->assertSame(14, interact_by_filters_from_request('POST', ['interval' => '14'], $session, 90)['interval']);
    }

    public function test_queue_options_map_the_page_filters_onto_the_use_by_queue(): void
    {
        $session = [];
        $filters = interact_by_filters_from_request('POST', [
            'sweetSpot' => '4',
            'minimumAge' => '10',
            'shelfSort' => 'yes',
            'hideSnoozed' => 'yes',
            'interval' => '30',
        ], $session, 90);

        $this->assertSame([
            'default_interval' => 30,
            'type_ids' => ['2', '5'],
            'sweet_spot' => '4',
            'minimum_age' => '10',
            'include_secondary_collection' => true,
            'hide_snoozed' => true,
        ], interact_by_queue_options($filters, ['2', '5']));
    }

    public function test_queue_options_leave_out_the_secondary_collection_and_show_snoozed_items_when_asked(): void
    {
        $session = [];
        $filters = interact_by_filters_from_request('POST', [], $session, 90);
        $queue = interact_by_queue_options($filters, []);

        $this->assertFalse($queue['include_secondary_collection']);
        $this->assertFalse($queue['hide_snoozed']);
        $this->assertSame([], $queue['type_ids']);
    }

    /** A Use-by queue entry: the item's columns plus use_by_status()'s answer. */
    private static function entry(array $columns = []): array
    {
        return $columns + [
            'id' => 7,
            'Title' => 'Azul',
            'type' => 'table-game',
            'Acq' => '2024-01-10',
            'last_use' => '2024-03-01',
            'snoozed_until' => null,
            'mnp' => 2,
            'mxp' => 4,
            'mnt' => 30,
            'mxt' => 45,
            'Candidate' => '',
            'ss' => '03,04',
            'age' => 8,
            'interval' => 90.0,
            'use_by_date' => '2024-08-28',
            'days_until' => -3,
            'status' => 'overdue',
            'is_snoozed' => false,
        ];
    }

    public function test_a_row_carries_the_entrys_queue_facts(): void
    {
        $row = interact_by_present_row(self::entry());

        $this->assertSame(7, $row['id']);
        $this->assertSame('Azul', $row['title']);
        $this->assertSame('table-game', $row['type']);
        $this->assertSame('2024-08-28', $row['use_by_date']);
        $this->assertTrue($row['overdue']);
        $this->assertSame('2024-03-01', $row['last_use']);
        $this->assertSame('2024-01-10', $row['acq']);
        $this->assertSame(90.0, $row['interval']);
        $this->assertFalse($row['is_snoozed']);
        $this->assertSame('', $row['snoozed_until']);
    }

    public function test_a_row_that_is_not_overdue_or_used_has_no_dates_to_show(): void
    {
        $row = interact_by_present_row(self::entry([
            'last_use' => null,
            'use_by_date' => null,
            'status' => null,
            'is_snoozed' => true,
            'snoozed_until' => '2024-09-01',
        ]));

        $this->assertFalse($row['overdue']);
        $this->assertNull($row['last_use']);
        $this->assertSame('', $row['use_by_date']);
        $this->assertTrue($row['is_snoozed']);
        $this->assertSame('2024-09-01', $row['snoozed_until']);
        $this->assertFalse(interact_by_present_row(self::entry(['status' => 'due_today']))['overdue']);
    }

    public function test_a_row_carries_the_attribute_columns_from_item_facts(): void
    {
        $row = interact_by_present_row(self::entry());

        // SwS is the lowest count of a zero-padded Sweet spot.
        $this->assertSame(3, $row['sws']);
        $this->assertSame(38, $row['avg_time']);
        $this->assertSame(8, $row['age']);
        $this->assertSame('03,04', $row['ss']);
        $this->assertSame('2', $row['mnp']);
        $this->assertSame('4', $row['mxp']);
        $this->assertFalse($row['candidate']);
    }

    public function test_a_row_without_recorded_facts_shows_blank_attributes(): void
    {
        $row = interact_by_present_row(self::entry([
            'ss' => '',
            'age' => 0,
            'mnp' => null,
            'mxp' => null,
            'mnt' => null,
            'mxt' => 60,
        ]));

        $this->assertSame('', $row['sws']);
        $this->assertSame('', $row['age']);
        $this->assertSame('', $row['ss']);
        $this->assertSame('', $row['mnp']);
        $this->assertSame('', $row['mxp']);
        // Only a maximum play time: the missing minimum counts as 0.
        $this->assertSame(30, $row['avg_time']);
    }

    public function test_a_zero_candidate_is_not_a_candidate(): void
    {
        $this->assertFalse(interact_by_present_row(self::entry(['Candidate' => '0']))['candidate']);
        $this->assertTrue(interact_by_present_row(self::entry(['Candidate' => '03: Ann, Ben at home']))['candidate']);
    }

    /** The page's filters on a POST of $post. */
    private static function filters(array $post = []): array
    {
        $session = [];
        return interact_by_filters_from_request('POST', $post, $session, 90);
    }

    public function test_a_signed_in_viewer_sees_record_and_get_rid_of_columns(): void
    {
        $table = interact_by_table(self::filters(), false);

        $this->assertSame(
            ['name', 'use_by_date', 'record', 'type', 'get_rid_of', 'overdue', 'last_use', 'acq'],
            $table['columns']
        );
        // Interact by, then recent interaction, then tracking start.
        $this->assertSame([[1, 'asc'], [6, 'asc'], [7, 'asc']], $table['order']);
    }

    public function test_a_guest_sees_no_record_or_get_rid_of_columns(): void
    {
        $table = interact_by_table(self::filters(), true);

        $this->assertSame(['name', 'use_by_date', 'type', 'overdue', 'last_use', 'acq'], $table['columns']);
        $this->assertSame([[1, 'asc'], [4, 'asc'], [5, 'asc']], $table['order']);
    }

    public function test_attributes_add_their_columns_after_type_and_order_by_interact_by_time_and_age(): void
    {
        $table = interact_by_table(self::filters(['showAttributes' => 'yes']), false);

        $this->assertSame([
            'name', 'use_by_date', 'record', 'type',
            'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate',
            'get_rid_of', 'overdue', 'last_use', 'acq',
        ], $table['columns']);
        $this->assertSame([[1, 'asc'], [5, 'asc'], [6, 'asc']], $table['order']);
    }

    public function test_the_interval_column_comes_last(): void
    {
        $table = interact_by_table(self::filters(['showInterval' => 'yes']), true);

        $this->assertSame(['name', 'use_by_date', 'type', 'overdue', 'last_use', 'acq', 'interval'], $table['columns']);
        $this->assertSame([[1, 'asc'], [4, 'asc'], [5, 'asc']], $table['order']);
    }

    public function test_shelf_sort_with_attributes_orders_by_type_and_the_attributes(): void
    {
        $table = interact_by_table(self::filters(['shelfSort' => 'yes', 'showAttributes' => 'yes', 'showInterval' => 'yes']), true);

        $this->assertSame([
            'name', 'use_by_date', 'type',
            'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate',
            'overdue', 'last_use', 'acq', 'interval',
        ], $table['columns']);
        $this->assertSame([
            [2, 'asc'], [3, 'asc'], [4, 'asc'], [5, 'asc'], [6, 'asc'], [7, 'asc'], [8, 'asc'],
            [11, 'desc'], [9, 'desc'],
        ], $table['order']);
    }

    public function test_shelf_sort_without_attributes_keeps_the_default_order(): void
    {
        $table = interact_by_table(self::filters(['shelfSort' => 'yes']), false);

        $this->assertSame([[1, 'asc'], [6, 'asc'], [7, 'asc']], $table['order']);
    }
}
