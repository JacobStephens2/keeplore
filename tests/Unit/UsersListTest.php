<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seams:
 * - ui/users/index.php source: search is in the first HTML with autofocus,
 *   independent of DataTables; the list is the items-style table
 * - ui/shared/js/users-list.js: filter/sort/page of the users table
 */
class UsersListTest extends TestCase
{
    public function test_users_page_search_is_in_the_document_and_autofocused(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/users/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="users-search"[^>]*autofocus/i',
            $source,
            'The users search box must be in the page HTML with autofocus so the cursor is ready on load.'
        );
        $searchPos = strpos($source, 'id="users-search"');
        $emptyPos = strpos($source, 'class="empty-state"');
        $this->assertNotFalse($searchPos);
        $this->assertNotFalse($emptyPos);
        $this->assertLessThan(
            $emptyPos,
            $searchPos,
            'Search must be rendered before the empty-state branch so it is present on load even with no users.'
        );
        $this->assertStringNotContainsString(
            'dataTable.html',
            $source,
            'Search must not be created by DataTables after the table is ready.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/new DataTable\\(\\s*['\"]#users['\"]/",
            $source,
            'The users table must not initialize DataTables.'
        );
        $this->assertStringContainsString('/shared/js/list-table.js', $source);
        $this->assertStringContainsString('/shared/js/users-list.js', $source);
        $queries = file_get_contents(PROJECT_PATH . '/private/query_functions/player_queries.php');
        $this->assertNotFalse($queries);
        $this->assertMatchesRegularExpression(
            '/function find_players_by_user_id\(\) \{[\s\S]*?ORDER BY id DESC/',
            $queries,
            'The users list query must return newest id first so first paint matches the old DataTables order.'
        );
    }

    public function test_users_page_marks_the_search_box_as_the_s_shortcut_target(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/users/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/id="users-search"[^>]*data-shortcut="search"|data-shortcut="search"[^>]*id="users-search"/',
            $source,
            'The users search box must be marked so s can focus it.'
        );
    }

    public function test_user_name_is_the_first_sortable_column(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/users/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<th data-sort="name" id="users-name-header">Name<\/th>/',
            $source,
            'Name must be the first sortable column, matching the items table pattern.'
        );
        $this->assertStringContainsString('data-sort="gender"', $source);
        $this->assertStringContainsString('data-sort="age"', $source);
        $this->assertStringContainsString('data-sort="id"', $source);
        $this->assertStringContainsString('id="users-list-body"', $source);
        $this->assertStringContainsString('id="users-list-pager"', $source);
    }

    public function test_users_list_search_and_sort_behavior(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/users-list.test.js';
        $cmd = 'node --test ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_shared_list_table_search_and_sort(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/list-table.test.js';
        $cmd = 'node --test ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
