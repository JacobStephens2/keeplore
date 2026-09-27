<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_types.php';

/**
 * Seam: item_type_is_game() / item_game_type_ids() decide which item types
 * count as games, for the Items type switch and the Select Games shortcut.
 */
class ItemTypesTest extends TestCase
{
    public function test_a_type_named_as_some_kind_of_game_or_sport_is_a_game(): void
    {
        foreach (['game', 'table game', 'card game', 'childrens game', 'role playing game', 'VR Game', 'sport'] as $name) {
            $this->assertTrue(item_type_is_game($name), $name . ' is a game');
        }
        foreach (['game component', 'other', 'equipment', 'toy', 'gamebook', '', 'endgame'] as $name) {
            $this->assertFalse(item_type_is_game($name), $name . ' is not a game');
        }
    }

    public function test_other_type_ids_are_the_types_named_other_in_any_case(): void
    {
        $this->assertSame(['44', '9'], item_other_type_ids(['book' => '4', 'other' => 44, 'Other ' => '9', 'others' => '7']));
        $this->assertSame([], item_other_type_ids(['book' => '4']));
    }

    public function test_game_type_ids_come_from_the_name_to_id_map(): void
    {
        $types = ['book' => '4', 'card game' => '81', 'game component' => '68', 'sport' => '47', 'table game' => 26];

        $this->assertSame(['81', '47', '26'], item_game_type_ids($types));
        $this->assertSame([], item_game_type_ids([]));
    }
}
