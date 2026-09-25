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
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => ''])['bgg_url']);
        $this->assertNull(item_bgg_fields_for_storage(['bgg_url' => 'javascript:alert(1)'])['bgg_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/171', item_bgg_fields_for_storage(['bgg_url' => 'http://boardgamegeek.com/boardgame/171'])['bgg_url']);
    }

    public function test_writers_store_the_bgg_vote_basis(): void
    {
        $writers = $this->source('/private/query_functions/artifact_queries.php');
        $this->assertMatchesRegularExpression('/INSERT INTO games\s*\([^)]*\bbgg_player_votes\b[^)]*\bbgg_age_basis\b/s', $writers);
        $this->assertStringContainsString('bgg_player_votes=?, bgg_age_basis=?', $this->updateWriter());
    }

    public function test_forms_carry_the_bgg_vote_basis(): void
    {
        foreach (['/ui/artifacts/new.php', '/ui/artifacts/edit.php'] as $path) {
            $page = $this->source($path);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_player_votes" id="bgg_player_votes"/', $page, $path);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_age_basis" id="bgg_age_basis"/', $page, $path);
        }
        $js = $this->source('/ui/artifacts/new-bgg.js');
        $this->assertStringContainsString('fields.bgg_player_votes', $js);
        $this->assertStringContainsString('fields.bgg_age_basis', $js);
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
            ['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => 19, 'bgg_age_basis' => 'community'],
            item_bgg_fields_for_storage(['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => '19', 'bgg_age_basis' => 'community'])
        );
        $this->assertSame(
            ['bgg_url' => null, 'bgg_player_votes' => null, 'bgg_age_basis' => null],
            item_bgg_fields_for_storage(['bgg_url' => '', 'bgg_player_votes' => '19', 'bgg_age_basis' => 'community'])
        );
        $this->assertSame(
            ['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => null, 'bgg_age_basis' => null],
            item_bgg_fields_for_storage(['bgg_url' => 'https://boardgamegeek.com/boardgame/621', 'bgg_player_votes' => 'many', 'bgg_age_basis' => 'guess'])
        );
    }
}
