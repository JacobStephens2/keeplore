<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seams:
 * - ui/users/edit.php: the #uses section records a use for this player
 *   without sending the user to /uses/record-new.php
 * - ui/uses/record-new.php: AJAX JSON includes the new use so the
 *   interactions table can update in place
 *
 * On Edit User, a compact form next to the recorded-interactions list
 * picks an item, posts through record-new.php, and stays on the page.
 */
class UserEditRecordUseTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/users/edit.php');
    }

    private function recordNew(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-new.php');
    }

    public function test_uses_section_has_a_record_use_form(): void
    {
        $page = $this->page();
        $usesPos = strpos($page, 'id="uses"');
        $this->assertNotFalse($usesPos, 'Edit User must keep the recorded-interactions section.');
        $uses = substr($page, $usesPos);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="record-use-form"/',
            $uses
        );
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="[^"]*\/uses\/record-new\.php/',
            $uses
        );
    }

    public function test_form_searches_items_and_submits_the_chosen_item(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('name="artifact[name]"', $page);
        $this->assertStringContainsString('name="artifact[id]"', $page);
        $this->assertStringContainsString('id="record-use-artifact-name"', $page);
        $this->assertStringContainsString('id="record-use-artifact-id"', $page);
        $this->assertStringContainsString('search-component.js', $page);
        $this->assertStringContainsString('id="record-use-item-options"', $page);
    }

    public function test_form_records_the_edited_player_as_a_participant(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('name="user[0][id]"', $page);
        $this->assertMatchesRegularExpression(
            '/name="user\[0\]\[id\]"[^>]*value="[^"]*\$id/',
            $page
        );
        $this->assertStringContainsString('name="user[0][name]"', $page);
    }

    public function test_form_has_date_setting_and_notes(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('name="useDate"', $page);
        $this->assertStringContainsString('name="Note"', $page);
        $this->assertStringContainsString('name="NotesTwo"', $page);
    }

    public function test_form_stays_on_the_page_after_save(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('X-Requested-With', $page);
        $this->assertStringContainsString('preventDefault', $page);
        $this->assertStringContainsString('table.row.add', $page);
        $this->assertStringContainsString('id="use-count"', $page);
        $this->assertStringContainsString('name="return_to"', $page);
        $this->assertStringContainsString('user-edit', $page);
        $source = $this->recordNew();
        $this->assertStringContainsString("\$return_player_id", $source);
        $this->assertStringContainsString('/users/edit.php?id=', $source);
    }

    public function test_ajax_response_includes_the_new_use_for_the_table(): void
    {
        $source = $this->recordNew();
        $this->assertStringContainsString("'use_id'", $source);
        $this->assertStringContainsString("'use_date'", $source);
        $this->assertStringContainsString("'artifact_name'", $source);
        $this->assertStringContainsString("'artifact_type'", $source);
    }
}
