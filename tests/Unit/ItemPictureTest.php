<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemPictureTest extends TestCase
{
    public function test_show_page_renders_the_stored_picture(): void
    {
        $show = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/show.php');
        $this->assertStringContainsString("\$object['image_url']", $show);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="item-picture"/',
            $show
        );
    }

    public function test_edit_page_renders_the_stored_picture(): void
    {
        $edit = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $this->assertStringContainsString("\$artifact['image_url']", $edit);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="item-picture"/',
            $edit
        );
    }
}
