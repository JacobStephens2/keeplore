<?php

/**
 * Which item types count as games. A type is a game when its name ends in
 * the word "game" (table game, card game, vr game, plain "game") or is
 * "sport"; "game component" is gear, not something to play.
 */

function item_type_is_game($name) {
  $name = strtolower(trim((string) $name));
  return $name === 'sport' || preg_match('/(^|\s)game$/', $name) === 1;
}

// The ids of the game types in a [type name => id] map, as strings, in map order.
function item_game_type_ids(array $types_by_name) {
  $ids = [];
  foreach ($types_by_name as $name => $id) {
    if (item_type_is_game($name)) {
      $ids[] = (string) $id;
    }
  }
  return $ids;
}
