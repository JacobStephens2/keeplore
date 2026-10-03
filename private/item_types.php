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

// The owner's types as [type name => id], by name.
function user_types($conn, int $user_id): array {
  $stmt = mysqli_prepare($conn, "SELECT id, objectType FROM types WHERE user_id = ? ORDER BY objectType ASC");
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $types = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $types[(string) $row['objectType']] = (int) $row['id'];
  }
  mysqli_stmt_close($stmt);
  return $types;
}

/**
 * The owner's Type for BoardGameGeek items, used on Create Item, as
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
 * Set the owner's Type for BoardGameGeek items from Settings. Blank clears
 * it. Returns ['ok' => true, 'type' => ['id', 'name']|null, 'message' =>
 * string|null], the message null when nothing changed, or ['ok' => false,
 * 'error' => string] when the input is not a type, the type is not the
 * owner's, or the database fails; then the setting stays.
 */
function user_bgg_default_type_set($conn, int $user_id, $raw): array {
  $raw = trim((string) $raw);
  if ($raw !== '' && preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
    return ['ok' => false, 'error' => 'That is not a type.'];
  }
  try {
    $type = null;
    if ($raw !== '') {
      $type = user_type($conn, $user_id, (int) $raw);
      if ($type === null) {
        return ['ok' => false, 'error' => 'That type is not one of yours.'];
      }
    }
    $value = $type['id'] ?? null;
    $stmt = mysqli_prepare($conn, "SELECT bgg_default_type_id FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row !== null && ($row[0] === null ? null : (int) $row[0]) === $value) {
      return ['ok' => true, 'type' => $type, 'message' => null];
    }
    $stmt = mysqli_prepare($conn, "UPDATE users SET bgg_default_type_id = ? WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $value, $user_id);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
  } catch (mysqli_sql_exception $e) {
    $saved = false;
  }
  if (!$saved) {
    return ['ok' => false, 'error' => 'Your type for BoardGameGeek items could not be saved. Please try again.'];
  }
  return ['ok' => true, 'type' => $type, 'message' => $type === null
    ? 'You no longer have a type for BoardGameGeek items.'
    : 'Your type for BoardGameGeek items is now ' . $type['name'] . '.'];
}

// One of the owner's types as ['id' => int, 'name' => string], or null when
// the id is not one of theirs.
function user_type($conn, int $user_id, int $type_id): ?array {
  $stmt = mysqli_prepare($conn, "SELECT id, objectType FROM types WHERE id = ? AND user_id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, 'ii', $type_id, $user_id);
  mysqli_stmt_execute($stmt);
  $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  return $row ? ['id' => (int) $row[0], 'name' => (string) $row[1]] : null;
}
