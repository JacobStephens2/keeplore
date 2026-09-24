<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_tags.php';
require_once PROJECT_PATH . '/private/kept_status.php';
require_once PROJECT_PATH . '/private/collection_list.php';

class CollectionListRequestTest extends TestCase
{
    private function parse(array $body): array
    {
        return parse_collection_list_request((object) $body);
    }

    public function test_empty_body_lists_page_one_of_basic_rows_with_no_filters(): void
    {
        $request = parse_collection_list_request(null);
        $this->assertSame([], $request['errors']);
        $this->assertSame(1, $request['page']);
        $this->assertSame(50, $request['per_page']);
        $this->assertFalse($request['use_cursor']);
        $this->assertNull($request['kept']);
        $this->assertNull($request['physical']);
        $this->assertSame([], $request['type_ids']);
        $this->assertSame('basic', $request['fields']);
        $this->assertFalse($request['include_uses_summary']);
    }

    public function test_issue_example_body_parses_into_filters(): void
    {
        $request = $this->parse([
            'kept' => true,
            'type_id' => [26, 39, 29],
            'physical' => true,
            'fields' => 'collection',
            'include' => ['uses_summary'],
            'per_page' => 200,
        ]);
        $this->assertSame([], $request['errors']);
        $this->assertTrue($request['kept']);
        $this->assertTrue($request['physical']);
        $this->assertSame([26, 39, 29], $request['type_ids']);
        $this->assertSame('collection', $request['fields']);
        $this->assertTrue($request['include_uses_summary']);
        $this->assertSame(200, $request['per_page']);
    }

    public function test_flag_filters_accept_json_and_form_style_booleans(): void
    {
        $request = $this->parse(['kept' => 0, 'digital' => '1', 'secondary_collection' => 'false']);
        $this->assertSame([], $request['errors']);
        $this->assertFalse($request['kept']);
        $this->assertTrue($request['digital']);
        $this->assertFalse($request['secondary_collection']);
    }

    public function test_single_type_id_and_include_string_are_accepted(): void
    {
        $request = $this->parse(['type_id' => '26', 'include' => 'uses_summary']);
        $this->assertSame([], $request['errors']);
        $this->assertSame([26], $request['type_ids']);
        $this->assertTrue($request['include_uses_summary']);
    }

    public function test_per_page_is_clamped_to_two_hundred(): void
    {
        $this->assertSame(200, $this->parse(['per_page' => 5000])['per_page']);
        $this->assertSame(1, $this->parse(['per_page' => 0])['per_page']);
        $this->assertSame(1, $this->parse(['page' => -3])['page']);
    }

    public function test_cursor_key_switches_to_cursor_pagination(): void
    {
        $first = $this->parse(['cursor' => null]);
        $this->assertTrue($first['use_cursor']);
        $this->assertNull($first['cursor']);
        $this->assertSame(42, $this->parse(['cursor' => 42])['cursor']);
    }

    public function test_invalid_values_are_reported_instead_of_silently_ignored(): void
    {
        $request = $this->parse([
            'kept' => 'maybe',
            'type_id' => ['games'],
            'fields' => 'everything',
            'include' => ['uses_summary', 'proposals'],
        ]);
        $this->assertCount(4, $request['errors']);
        $joined = implode(' ', $request['errors']);
        $this->assertStringContainsString('kept', $joined);
        $this->assertStringContainsString('type_id', $joined);
        $this->assertStringContainsString('fields', $joined);
        $this->assertStringContainsString('proposals', $joined);
    }

    public function test_query_and_tag_are_trimmed_strings(): void
    {
        $request = $this->parse(['query' => '  catan ', 'tag' => 'Beach-Safe']);
        $this->assertSame('catan', $request['query']);
        $this->assertSame('Beach-Safe', $request['tag']);
    }

    public function test_non_string_query_tag_and_include_are_errors_not_crashes(): void
    {
        $request = $this->parse(['query' => ['a'], 'tag' => (object) ['x' => 1], 'include' => [['nested']]]);
        $this->assertCount(3, $request['errors']);
        $this->assertSame('', $request['query']);
        $this->assertSame('', $request['tag']);
    }

    public function test_has_collection_filters_reports_whether_any_filter_is_set(): void
    {
        $this->assertFalse(collection_list_has_filters($this->parse(['page' => 2, 'per_page' => 10])));
        $this->assertTrue(collection_list_has_filters($this->parse(['query' => 'catan'])));
        $this->assertTrue(collection_list_has_filters($this->parse(['kept' => false])));
        $this->assertTrue(collection_list_has_filters($this->parse(['type_id' => 3])));
        $this->assertTrue(collection_list_has_filters($this->parse(['fields' => 'collection'])));
    }
}
