<?php

/**
 * Which item types fall in the Items page's type categories: games, and
 * Other (items still waiting for a real type).
 *
 * Which item types count as games. A type is a game when its name ends in
 * the word "game" (table game, card game, vr game, plain "game") or is
 * "sport"; "game component" is gear, not something to play.
 */

function item_type_is_game($name) {
  $name = strtolower(trim((string) $name));
  return $name === 'sport' || preg_match('/(^|\s)game$/', $name) === 1;
}

function item_type_is_other($name) {
  return strtolower(trim((string) $name)) === 'other';
}

// The ids of the game types in a [type name => id] map, as strings, in map order.
function item_game_type_ids(array $types_by_name) {
  return item_type_ids_where($types_by_name, 'item_type_is_game');
}

// The ids of the types named "other", in any case, as strings, in map order.
function item_other_type_ids(array $types_by_name) {
  return item_type_ids_where($types_by_name, 'item_type_is_other');
}

function item_type_ids_where(array $types_by_name, callable $matches) {
  $ids = [];
  foreach ($types_by_name as $name => $id) {
    if ($matches($name)) {
      $ids[] = (string) $id;
    }
  }
  return $ids;
}
