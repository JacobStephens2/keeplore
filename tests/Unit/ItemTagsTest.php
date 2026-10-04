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
}
