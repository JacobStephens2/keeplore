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

/**
 * The owner's type for items filled from BoardGameGeek on Create Item, as
 * ['id' => int, 'name' => string], or null when none is set or the stored
 * type is no longer one of the owner's.
 */
function user_bgg_default_type($conn, int $user_id): ?array {
  $stmt = mysqli_prepare($conn, "SELECT types.id, types.objectType
    FROM users
    JOIN types ON types.id = users.bgg_default_type_id AND types.user_id = users.id
    WHERE users.id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  return $row ? ['id' => (int) $row[0], 'name' => (string) $row[1]] : null;
}

/**
 * Set the owner's BoardGameGeek type from Settings. Blank clears it; a type
 * that is not the owner's is refused (false) and the setting stays.
 */
function user_bgg_default_type_set($conn, int $user_id, $raw): bool {
  $type_id = (int) trim((string) $raw);
  if ($type_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM types WHERE id = ? AND user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $type_id, $user_id);
    mysqli_stmt_execute($stmt);
    $owned = mysqli_fetch_row(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    if (!$owned) {
      return false;
    }
  }
  $value = $type_id > 0 ? $type_id : null;
  $stmt = mysqli_prepare($conn, "UPDATE users SET bgg_default_type_id = ? WHERE id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, 'ii', $value, $user_id);
  $ok = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  return $ok;
}
