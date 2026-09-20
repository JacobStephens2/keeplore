<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_tags.php';

class ItemTagsTest extends TestCase
{
    public function test_normalize_item_tag_trims_and_lowercases(): void
    {
        $this->assertSame('beach-safe', normalize_item_tag('  Beach-Safe  '));
    }

    public function test_normalize_item_tag_collapses_internal_whitespace(): void
    {
        $this->assertSame('two player', normalize_item_tag("two   \tplayer"));
    }

    public function test_normalize_item_tag_rejects_blank(): void
    {
        $this->assertNull(normalize_item_tag('   '));
        $this->assertNull(normalize_item_tag(''));
    }

    public function test_normalize_item_tag_truncates_overlong_labels(): void
    {
        $this->assertSame(str_repeat('a', 64), normalize_item_tag(str_repeat('a', 65)));
        $this->assertSame(str_repeat('a', 64), normalize_item_tag(str_repeat('a', 64)));
    }

    public function test_parse_item_tags_input_splits_commas_dedupes_and_sorts(): void
    {
        $this->assertSame(
            ['beach-safe', 'party', 'portable'],
            parse_item_tags_input('Portable, beach-safe, portable, Party')
        );
    }

    public function test_parse_item_tags_input_accepts_an_array(): void
    {
        $this->assertSame(
            ['party', 'two-player'],
            parse_item_tags_input(['Two-Player', ' party ', 'two-player'])
        );
    }

    public function test_attach_item_tags_fills_matching_records_and_defaults_to_empty(): void
    {
        $items = [
            ['id' => 10, 'Title' => 'Catan'],
            ['id' => 11, 'Title' => 'Azul'],
        ];
        $result = attach_item_tags($items, [
            10 => ['beach-safe', 'portable'],
        ]);

        $this->assertSame(['beach-safe', 'portable'], $result[0]['tags']);
        $this->assertSame([], $result[1]['tags']);
        $this->assertArrayNotHasKey('tags', $items[0]);
    }

    public function test_attach_item_tags_sets_tags_on_objects_without_mutating_the_original(): void
    {
        $item = new \stdClass();
        $item->id = 10;
        $item->Title = 'Catan';

        $result = attach_item_tags([$item], [10 => ['party']]);

        $this->assertSame(['party'], $result[0]->tags);
        $this->assertFalse(isset($item->tags));
    }
}
