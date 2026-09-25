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
        $this->assertStringContainsString("\$artifact['bgg_url'] = \$_POST['bgg_url'] ?? '';", $new);
        $this->assertStringContainsString('fields.bgg_url', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_edit_form_has_an_editable_bgg_link(): void
    {
        $edit = $this->source('/ui/artifacts/edit.php');
        $this->assertMatchesRegularExpression('/<input type="url" name="bgg_url" id="bgg_url"/', $edit);
        $this->assertStringContainsString("\$artifact['bgg_url'] = \$_POST['bgg_url'] ?? '';", $edit);
    }

    public function test_edit_and_show_pages_link_to_the_item(): void
    {
        foreach (['/ui/artifacts/edit.php' => '$artifact', '/ui/artifacts/show.php' => '$object'] as $path => $var) {
            $page = $this->source($path);
            $this->assertStringContainsString("item_bgg_link_html({$var}['bgg_url']", $page, $path);
        }
    }

    public function test_create_and_edit_share_the_bgg_lookup_panel(): void
    {
        $panel = $this->source('/private/shared/bgg_lookup_panel.php');
        foreach (['requestBggData', 'bggLookupStatus', 'bggConfirm', 'bggUseMatch', 'bggOtherMatches'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $panel, $id);
        }
        foreach (['/ui/artifacts/new.php', '/ui/artifacts/edit.php'] as $path) {
            $page = $this->source($path);
            $this->assertStringContainsString("SHARED_PATH . '/bgg_lookup_panel.php'", $page, $path);
            $this->assertStringContainsString("url_for('/artifacts/new-bgg.js')", $page, $path);
        }
    }

    public function test_edit_lookup_keeps_the_item_name(): void
    {
        $this->assertStringContainsString("\$bgg_keep_title = true;", $this->source('/ui/artifacts/edit.php'));
        $this->assertStringContainsString('data-keep-title', $this->source('/private/shared/bgg_lookup_panel.php'));
        $this->assertStringContainsString('keepTitle', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_a_link_to_another_site_is_a_validation_error(): void
    {
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
        $item = ['Title' => 'Chess', 'is_kept' => '1'];

        $this->assertSame([], validate_artifact($item + ['bgg_url' => 'boardgamegeek.com/boardgame/171/chess']));
        $this->assertSame([], validate_artifact($item + ['bgg_url' => '']));
        $this->assertContains(
            'BoardGameGeek Link must be a boardgamegeek.com, rpggeek.com, or videogamegeek.com page.',
            validate_artifact($item + ['bgg_url' => 'https://example.com/chess'])
        );
    }

    public function test_stored_link_is_null_when_blank(): void
    {
        $this->assertNull(item_bgg_url_for_storage(''));
        $this->assertNull(item_bgg_url_for_storage('javascript:alert(1)'));
        $this->assertSame('https://boardgamegeek.com/boardgame/171', item_bgg_url_for_storage('http://boardgamegeek.com/boardgame/171'));
    }
}
