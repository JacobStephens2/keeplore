<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemBggLinkTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(PROJECT_PATH . $path);
    }

    private function updateWriter(): string
    {
        preg_match(
            '/function update_artifact\s*\(.*?\n  \}/s',
            $this->source('/private/query_functions/artifact_queries.php'),
            $match
        );
        return $match[0] ?? '';
    }

    public function test_insert_writes_bgg_url(): void
    {
        $this->assertMatchesRegularExpression(
            '/INSERT INTO games\s*\([^)]*\bbgg_url\b/s',
            $this->source('/private/query_functions/artifact_queries.php')
        );
    }

    public function test_update_writes_bgg_url(): void
    {
        $this->assertStringContainsString('bgg_url=?', $this->updateWriter());
    }

    public function test_create_form_carries_the_bgg_link(): void
    {
        $new = $this->source('/ui/artifacts/new.php');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_url" id="bgg_url"/', $new);
        $this->assertStringContainsString("normalize_item_bgg_url(\$_POST['bgg_url']", $new);
        $this->assertStringContainsString('fields.bgg_url', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_edit_form_has_an_editable_bgg_link(): void
    {
        $edit = $this->source('/ui/artifacts/edit.php');
        $this->assertMatchesRegularExpression('/<input type="url" name="bgg_url" id="bgg_url"/', $edit);
        $this->assertStringContainsString("normalize_item_bgg_url(\$_POST['bgg_url']", $edit);
    }

    public function test_edit_and_show_pages_link_to_the_game(): void
    {
        foreach (['/ui/artifacts/edit.php' => '$artifact', '/ui/artifacts/show.php' => '$object'] as $path => $var) {
            $page = $this->source($path);
            $this->assertStringContainsString("normalize_item_bgg_url({$var}['bgg_url']", $page, $path);
            $this->assertMatchesRegularExpression('/<a class="item-bgg-link"[^\n]*rel="noopener noreferrer"/', $page, $path);
        }
    }
}
