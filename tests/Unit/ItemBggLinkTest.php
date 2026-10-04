<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemBggLinkTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(PROJECT_PATH . $path);
    }

    // The Item form's BGG fields on each page: tests/Integration/ItemFormTest.php.

    public function test_the_lookup_fills_the_bgg_link(): void
    {
        $this->assertStringContainsString('fields.bgg_url', $this->source('/ui/artifacts/new-bgg.js'));
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
            $this->assertStringContainsString("url_for('/artifacts/new-bgg.js')", $this->source($path), $path);
        }
    }

    public function test_the_lookup_can_keep_the_item_name(): void
    {
        $this->assertStringContainsString('data-keep-title', $this->source('/private/shared/bgg_lookup_panel.php'));
        $this->assertStringContainsString('keepTitle', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_stored_link_is_null_when_blank(): void
    {
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => ''])['bgg_url']);
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => 'javascript:alert(1)'])['bgg_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/171', item_bgg_fields_for_storage(['bgg_url' => 'http://boardgamegeek.com/boardgame/171'])['bgg_url']);
    }

    public function test_the_lookup_fills_the_bgg_vote_basis(): void
    {
        $js = $this->source('/ui/artifacts/new-bgg.js');
        $this->assertStringContainsString('fields.bgg_player_votes', $js);
        $this->assertStringContainsString('fields.bgg_age_basis', $js);
        $this->assertStringContainsString('fields.BGG_Rat', $js);
    }

    public function test_show_page_states_the_bgg_vote_basis(): void
    {
        $this->assertStringContainsString('item_bgg_basis_html($object)', $this->source('/ui/artifacts/show.php'));
    }

    public function test_edit_page_has_no_summary_vote_basis(): void
    {
        $this->assertStringNotContainsString('item_bgg_basis_html(', $this->source('/ui/artifacts/edit.php'));
    }

    public function test_bgg_lookup_returns_the_field_bases(): void
    {
        $this->assertStringContainsString("item_bgg_field_bases(\$result['fields'])", $this->source('/ui/artifacts/bgg-data.php'));
        $this->assertStringContainsString('showBases(pending.basis)', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_hand_edits_hide_the_inline_basis(): void
    {
        $js = $this->source('/ui/artifacts/new-bgg.js');
        $this->assertStringContainsString('[data-bgg-basis="players"]', $js);
        $this->assertStringContainsString('[data-bgg-basis="age"]', $js);
    }

    public function test_hand_edits_drop_the_bgg_vote_basis(): void
    {
        $js = $this->source('/ui/artifacts/new-bgg.js');
        $this->assertStringContainsString('["MnP", "MxP", "SS", "bgg_url"]', $js);
        $this->assertStringContainsString('["age", "bgg_url"]', $js);
    }

    public function test_bgg_storage_drops_the_vote_basis_without_a_link(): void
    {
        $this->assertSame(
            ['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => 19, 'bgg_age_basis' => 'community', 'BGG_Rat' => '7.09'],
            item_bgg_fields_for_storage(['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => '19', 'bgg_age_basis' => 'community', 'BGG_Rat' => '7.09024'])
        );
        $this->assertSame(
            ['bgg_url' => null, 'bgg_player_votes' => null, 'bgg_age_basis' => null, 'BGG_Rat' => null],
            item_bgg_fields_for_storage(['bgg_url' => '', 'bgg_player_votes' => '19', 'bgg_age_basis' => 'community', 'BGG_Rat' => '7.09'])
        );
        $this->assertSame(
            ['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => null, 'bgg_age_basis' => null, 'BGG_Rat' => null],
            item_bgg_fields_for_storage(['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => 'many', 'bgg_age_basis' => 'guess', 'BGG_Rat' => 'nope'])
        );
    }
}
