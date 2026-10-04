<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seams:
 * - ui/artifacts/to-get-rid-of.php: in-place kept toggle, not a trip to delete.php
 * - ui/artifacts/set-tracked.php: no-JS POST returns to the same page
 *
 * /artifacts/to-get-rid-of unkeeps through the same set-tracked.php seam as
 * /artifacts/, stays on the page, and confirms before removing from kept.
 */
class ToGetRidOfPageTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/to-get-rid-of.php');
    }

    public function test_page_does_not_send_the_user_to_delete(): void
    {
        $page = $this->page();
        $this->assertStringNotContainsString(
            '/artifacts/delete.php',
            $page,
            'To Get Rid Of must not navigate to delete.php. Unkeep in place instead.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*Delete\s*</',
            $page
        );
    }

    public function test_page_toggles_kept_through_set_tracked_and_stays_put(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('/artifacts/set-tracked.php', $page);
        $this->assertStringContainsString('kept-toggle-form', $page);
        $this->assertStringContainsString('kept-toggle-btn', $page);
        $this->assertStringContainsString('return_to" value="to-get-rid-of"', $page);
        $this->assertStringContainsString('artifact_is_kept', $page);
        $this->assertStringContainsString('X-Requested-With', $page);
        $this->assertStringContainsString('response.json', $page);
    }

    public function test_unkeep_asks_for_confirm(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('confirm(', $page);
        $this->assertStringContainsString('from kept', $page);
    }

    public function test_set_tracked_returns_to_to_get_rid_of(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/set-tracked.php');
        $this->assertStringContainsString("\$return_to === 'to-get-rid-of'", $source);
        $this->assertStringContainsString('/artifacts/to-get-rid-of.php', $source);
    }
}
