<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemBggLinkTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(PROJECT_PATH . $path);
    }

    public function test_create_form_carries_the_bgg_link(): void
    {
        $new = $this->source('/ui/artifacts/new.php');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_url" id="bgg_url"/', $new);
        $this->assertStringContainsString('item_input_from_form($_POST)', $new);
        $this->assertStringContainsString('fields.bgg_url', $this->source('/ui/artifacts/new-bgg.js'));
    }

    public function test_edit_form_has_an_editable_bgg_link(): void
    {
        $edit = $this->source('/ui/artifacts/edit.php');
        $this->assertMatchesRegularExpression('/<input type="url" name="bgg_url" id="bgg_url"/', $edit);
        $this->assertStringContainsString('item_input_from_form($_POST)', $edit);
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

    public function test_stored_link_is_null_when_blank(): void
    {
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => ''])['bgg_url']);
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => 'javascript:alert(1)'])['bgg_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/171', item_bgg_fields_for_storage(['bgg_url' => 'http://boardgamegeek.com/boardgame/171'])['bgg_url']);
    }

    public function test_forms_carry_the_bgg_vote_basis(): void
    {
        foreach (['/ui/artifacts/new.php', '/ui/artifacts/edit.php'] as $path) {
            $page = $this->source($path);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_player_votes" id="bgg_player_votes"/', $page, $path);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_age_basis" id="bgg_age_basis"/', $page, $path);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="BGG_Rat" id="BGG_Rat"/', $page, $path);
        }
        $js = $this->source('/ui/artifacts/new-bgg.js');
        $this->assertStringContainsString('fields.bgg_player_votes', $js);
        $this->assertStringContainsString('fields.bgg_age_basis', $js);
        $this->assertStringContainsString('fields.BGG_Rat', $js);
    }

    public function test_show_page_states_the_bgg_vote_basis(): void
    {
        $this->assertStringContainsString('item_bgg_basis_html($object)', $this->source('/ui/artifacts/show.php'));
    }

    public function test_edit_page_puts_the_vote_basis_under_each_bgg_field(): void
    {
        $edit = $this->source('/ui/artifacts/edit.php');
        $this->assertStringNotContainsString('item_bgg_basis_html(', $edit);
        foreach (['SS' => 'sweet_spot', 'age' => 'age', 'MnP' => 'players', 'MxP' => 'players'] as $id => $group) {
            $this->assertMatchesRegularExpression(
                '/id="' . $id . '"[^\n]*aria-describedby="' . $id . '-bgg-basis"[^\n]*\n\s*<\?php echo item_bgg_field_basis_html\(\$artifact, \'' . $group . '\', \'' . $id . '\'\); \?>/',
                $edit,
                "{$id} should be described by its {$group} basis"
            );
        }
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
