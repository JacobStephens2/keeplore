<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemPictureTest extends TestCase
{
    private function writers(): string
    {
        return (string) file_get_contents(
            PROJECT_PATH . '/private/query_functions/artifact_queries.php'
        );
    }

    public function test_insert_writes_image_url(): void
    {
        $this->assertMatchesRegularExpression(
            '/INSERT INTO games\s*\([^)]*\bimage_url\b/s',
            $this->writers()
        );
    }

    public function test_update_does_not_write_image_url(): void
    {
        $this->assertSame(
            1,
            preg_match('/function update_artifact\s*\(.*?\n  \}/s', $this->writers(), $match),
            'update_artifact must exist so the edit writer can be checked.'
        );
        $this->assertStringNotContainsString(
            'image_url',
            $match[0],
            'Edit must leave games.image_url untouched so saving an edit does not clear the BGG cover.'
        );
    }

    public function test_show_page_renders_the_stored_picture(): void
    {
        $show = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/show.php');
        $this->assertStringContainsString("\$object['image_url']", $show);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="item-picture"/',
            $show
        );
    }
}
