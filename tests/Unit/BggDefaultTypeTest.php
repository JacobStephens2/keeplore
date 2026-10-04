<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: BggDefaultType.apply(document, button).
 *
 * Using a BoardGameGeek match on Create Item sets Type to the owner's Type
 * for BoardGameGeek items (data-default-type-id/-name on the Request BGG Data button),
 * unless the owner already picked a Type other than Create Item's own
 * default (data-form-default-type-id). A blank Type counts as not picked.
 */
class BggDefaultTypeTest extends TestCase
{
    private const BUTTON = ['defaultTypeId' => '7', 'defaultTypeName' => 'table game', 'formDefaultTypeId' => '44'];

    public function test_using_a_match_on_the_form_default_sets_the_owners_type_for_bgg_items(): void
    {
        $this->assertSame(
            ['applied' => true, 'type' => '7', 'search' => 'table game'],
            $this->apply(self::BUTTON, '44')
        );
    }

    public function test_a_type_the_owner_already_picked_stays(): void
    {
        $this->assertSame(
            ['applied' => false, 'type' => '12', 'search' => 'book'],
            $this->apply(self::BUTTON, '12', 'book')
        );
    }

    public function test_a_blank_type_from_unmatched_search_text_gets_the_type_for_bgg_items(): void
    {
        $this->assertSame(
            ['applied' => true, 'type' => '7', 'search' => 'table game'],
            $this->apply(self::BUTTON, '', 'boo')
        );
    }

    public function test_without_a_type_for_bgg_items_nothing_changes(): void
    {
        $this->assertSame(
            ['applied' => false, 'type' => '44', 'search' => 'other'],
            $this->apply(['formDefaultTypeId' => '44'], '44')
        );
    }

    public function test_edit_items_button_never_changes_type(): void
    {
        $this->assertSame(
            ['applied' => false, 'type' => '', 'search' => 'boo'],
            $this->apply(['keepTitle' => ''], '', 'boo')
        );
    }

    public function test_create_item_carries_the_owners_type_for_bgg_items_on_the_button(): void
    {
        $page = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $panel = (string) file_get_contents(PROJECT_PATH . '/private/shared/bgg_lookup_panel.php');

        // The Item form puts the type on the button: tests/Integration/ItemFormTest.php.
        $this->assertStringContainsString('bgg-default-type.js', $page);
        $this->assertStringContainsString('data-default-type-id=', $panel);
        $this->assertStringContainsString("(new Types(\$db, (int) \$_SESSION['user_id']))->bggDefault()", (string) file_get_contents(PROJECT_PATH . '/private/item_form.php'));
        $this->assertStringContainsString('data-form-default-type-id=', $panel);
        $this->assertStringNotContainsString('initialTypeId', (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/new-bgg.js'));
    }

    public function test_settings_offers_and_saves_the_type_for_bgg_items(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/settings/edit.php');

        $this->assertStringContainsString('name="bgg_default_type_id"', $source);
        $this->assertStringContainsString('$types_module = new Types($db, $user_id);', $source);
        $this->assertStringContainsString('$types_module->setBggDefault(', $source);
        $this->assertStringContainsString('$bgg_default_type = $types_module->bggDefault();', $source);
        $this->assertStringContainsString('$types = $types_module->all();', $source);
        $this->assertStringNotContainsString('artifact_type_array.php', $source);
        $this->assertSame(1, substr_count($source, '$user_id = '), 'Settings sets $user_id once');
    }

    /**
     * @param array<string, string> $dataset
     * @return array{applied: bool, type: string, search: string}
     */
    private function apply(array $dataset, string $current, string $currentName = 'other'): array
    {
        $module = json_encode(PROJECT_PATH . '/ui/artifacts/bgg-default-type.js');
        $args = json_encode(['dataset' => $dataset, 'current' => $current, 'name' => $currentName]);
        $script = <<<JS
const BggDefaultType = require({$module});
const a = {$args};
const fields = { type: { value: a.current }, type_search: { value: a.name } };
const doc = { getElementById: (id) => fields[id] || null };
const applied = BggDefaultType.apply(doc, { dataset: a.dataset });
process.stdout.write(JSON.stringify({ applied, type: fields.type.value, search: fields.type_search.value }));
JS;

        $cmd = 'node -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
