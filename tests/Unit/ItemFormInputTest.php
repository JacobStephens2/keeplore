<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: item_input_from_form(), the Create Item and Edit Item forms'
 * translation of their post into the Items module's input keys.
 */
class ItemFormInputTest extends TestCase
{
    public function test_form_fields_become_the_items_module_keys(): void
    {
        $this->assertEquals(
            ['Title' => 'Quelf', 'type_id' => '4', 'Age' => '12', 'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'tags' => 'party'],
            item_input_from_form(['Title' => 'Quelf', 'type' => '4', 'age' => '12', 'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'tags' => 'party'])
        );
    }

    public function test_fields_the_post_does_not_carry_stay_missing(): void
    {
        $this->assertSame(['Title' => 'Quelf'], item_input_from_form(['Title' => 'Quelf']));
    }
}
