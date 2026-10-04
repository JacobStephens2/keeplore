<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PRIVATE_PATH . '/item_form.php';

/**
 * Seam: item_form_input(), the Item form's reading half, which turns a
 * posted Create Item or Edit Item form into the Items module's input.
 */
class ItemFormInputTest extends TestCase
{
    public function test_form_fields_become_the_items_module_keys(): void
    {
        $this->assertEquals(
            ['Title' => 'Quelf', 'type_id' => '4', 'Age' => '12', 'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'tags' => 'party'],
            item_form_input(['Title' => 'Quelf', 'type' => '4', 'age' => '12', 'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'tags' => 'party'])
        );
    }

    public function test_every_item_field_is_read(): void
    {
        $post = [
            'Title' => 'Quelf', 'type' => '4', 'tags' => 'party', 'Acq' => '2026-01-02',
            'interaction_frequency_days' => '30', 'SS' => '4', 'age' => '12', 'MnP' => '3', 'MxP' => '8',
            'MnT' => '20', 'MxT' => '45', 'Yr' => '2004', 'Notes' => 'Loud', 'image_url' => 'https://example.com/q.jpg',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'bgg_player_votes' => '9',
            'bgg_age_basis' => 'community', 'BGG_Rat' => '6.1',
            'is_kept' => '1', 'to_get_rid_of' => '0', 'is_in_secondary_collection' => '1',
        ];

        $this->assertEquals([
            'Title' => 'Quelf', 'type_id' => '4', 'tags' => 'party', 'Acq' => '2026-01-02',
            'interaction_frequency_days' => '30', 'SS' => '4', 'Age' => '12', 'MnP' => '3', 'MxP' => '8',
            'MnT' => '20', 'MxT' => '45', 'Yr' => '2004', 'Notes' => 'Loud', 'image_url' => 'https://example.com/q.jpg',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/19370', 'bgg_player_votes' => '9',
            'bgg_age_basis' => 'community', 'BGG_Rat' => '6.1',
            'is_kept' => '1', 'to_get_rid_of' => '0', 'is_in_secondary_collection' => '1',
        ], item_form_input($post));
    }

    public function test_unchecked_boxes_post_their_hidden_no(): void
    {
        // Each box's hidden input posts 0; a checked box's 1 replaces it.
        $this->assertSame(
            ['is_kept' => '0', 'to_get_rid_of' => '0', 'is_in_secondary_collection' => '0'],
            item_form_input(['is_kept' => '0', 'to_get_rid_of' => '0', 'is_in_secondary_collection' => '0'])
        );
    }

    public function test_fields_the_post_does_not_carry_stay_missing(): void
    {
        $this->assertSame(['Title' => 'Quelf'], item_form_input(['Title' => 'Quelf']));
    }

    public function test_fields_outside_the_form_are_dropped(): void
    {
        $this->assertSame(
            ['Title' => 'Quelf'],
            item_form_input(['Title' => 'Quelf', 'csrf_token' => 'abc', 'id' => '9', 'user_id' => '2'])
        );
    }
}
