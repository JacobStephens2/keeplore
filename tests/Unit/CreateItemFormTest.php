<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seams:
 * - ui/artifacts/new.php: Name autofocus, save shortcut, collection lookup
 *   markup (search outside the create form, Kept then Name)
 * - ui/shared/js/create-item-lookup.js: empty query matches nothing; name
 *   search; kept sort (node tests)
 * - ui/artifacts/set-tracked.php: return_to=new stays on Create Item
 */
class CreateItemFormTest extends TestCase
{
    public function test_name_field_autofocuses_on_create_item(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/id="Title"[^>\n]{0,80}autofocus/',
            $source,
            'The Name field (id Title) must autofocus so the cursor is ready on load.'
        );
    }

    public function test_bgg_confirm_has_a_picture_slot(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $panel = file_get_contents(PRIVATE_PATH . '/shared/bgg_lookup_panel.php');
        $this->assertNotFalse($panel);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*id="bggMatchImage"/',
            $panel,
            'The BGG confirm panel must include img#bggMatchImage for the found game\'s cover.'
        );
        $this->assertStringContainsString('id="image_url"', $source);
        $this->assertStringContainsString('name="image_url"', $source);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*id="itemPicturePreview"/',
            $source,
            'Use this game must keep a visible cover preview on the create form.'
        );
    }

    public function test_create_item_submit_is_above_the_name_field(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $titlePos = strpos($source, 'id="Title"');
        $this->assertNotFalse($titlePos);
        $beforeTitle = substr($source, 0, $titlePos);
        $this->assertMatchesRegularExpression(
            '/<(?:input|button)[^>]*type="submit"/',
            $beforeTitle,
            'A Create Item submit must sit above Name so the form can be saved without scrolling.'
        );
        $this->assertStringContainsString('Create Item', $beforeTitle);
    }

    public function test_s_submits_create_item_when_not_in_a_text_field(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-shortcut="save"/',
            $source,
            'Create Item must mark its form so s can save it.'
        );
        $this->assertStringContainsString('form-save-shortcut.js', $source);
    }

    public function test_create_item_can_search_the_collection_and_toggle_kept(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);

        $searchPos = strpos($source, 'id="create-item-lookup-search"');
        $formPos = strpos($source, 'data-shortcut="save"');
        $this->assertNotFalse($searchPos, 'Create Item must include a collection search box.');
        $this->assertNotFalse($formPos);
        $this->assertLessThan(
            $formPos,
            $searchPos,
            'Collection search must sit outside the create form so looking up an item cannot submit a duplicate.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/id="create-item-lookup-search"[^>]*autofocus|autofocus[^>]*id="create-item-lookup-search"/',
            $source,
            'Collection search must not steal autofocus from Name.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="create-item-lookup-search"[^>]*data-shortcut="search"|data-shortcut="search"[^>]*id="create-item-lookup-search"/',
            $source,
            'Collection search must not steal s from Create Item save.'
        );

        $this->assertMatchesRegularExpression(
            '/<th data-sort="is_kept">Kept<\/th>\s*<th data-sort="title" id="create-item-lookup-name-header">Name<\/th>/',
            $source,
            'Lookup results must show Kept then Name so you can see whether the match is already kept.'
        );
        $this->assertStringContainsString('id="create-item-lookup-body"', $source);
        $this->assertStringContainsString('/artifacts/items-data.php', $source);
        $this->assertStringContainsString('/artifacts/set-tracked.php', $source);
        $this->assertStringContainsString('/shared/js/list-table.js', $source);
        $this->assertStringContainsString('/shared/js/create-item-lookup.js', $source);
        $this->assertStringContainsString('return_to', $source);
        $this->assertStringContainsString("'new'", $source);
    }

    public function test_set_tracked_returns_to_create_item(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/set-tracked.php');
        $this->assertStringContainsString("\$return_to === 'new'", $source);
        $this->assertStringContainsString('/artifacts/new', $source);
    }

    public function test_create_item_lookup_search_and_sort_behavior(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/create-item-lookup.test.js';
        $cmd = 'node --test ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
