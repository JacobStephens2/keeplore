<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: Explore's pages and Record Use read the owner's items through the
 * Explore module and Items, never with SQL of their own. Their reads:
 * tests/Integration/ExploreTest.php
 */
class ExplorePagesTest extends TestCase
{
    private const PAGES = ['/ui/explore/candidates.php', '/ui/explore/index.php', '/ui/uses/record-new.php'];

    public function test_pages_hold_no_sql(): void
    {
        foreach (self::PAGES as $path) {
            $page = (string) file_get_contents(PROJECT_PATH . $path);
            $this->assertStringNotContainsString('SELECT', $page, $path);
            $this->assertStringNotContainsString('mysqli_prepare', $page, $path);
        }
    }

    public function test_items_by_characteristic_takes_its_types_from_the_type_filter(): void
    {
        $page = (string) file_get_contents(PROJECT_PATH . '/ui/explore/index.php');
        $this->assertStringContainsString('type_filter(', $page);
        $this->assertStringContainsString('artifact_type_checkboxes.php', $page);
        $this->assertStringNotContainsString('allGames', $page);
    }
}
