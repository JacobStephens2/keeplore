<?php

require_once __DIR__ . '/classes/Types.php';
require_once __DIR__ . '/item_types.php';

/**
 * The Type slugs each type checkbox shortcut beyond Games ticks. A slug is
 * the Type name with spaces turned into hyphens.
 */
const TYPE_FILTER_SHORTCUT_SLUGS = [
  'analog' => ['gambling-game', 'game', 'role-playing-game', 'sport', 'table-game'],
  'online' => ['individual-display', 'mobile-game', 'vr-game'],
  'outdoor' => ['gambling-game', 'game', 'mobile-game', 'sport', 'toy', 'equipment'],
];

/**
 * Type filter: which of the owner's Types a page shows, shared by Interact
 * By, Item Interactions, Candidates and Choose for group through the
 * session's `type` key.
 *
 * Returns ['types' => [name => id], 'selected' => [id strings]], both in the
 * owner's Type order; the answer the page queries with and hands to the
 * type checkboxes.
 *
 * A POST selects the ticked Types (none ticked selects none) and remembers
 * them. A GET uses the remembered selection, or all the owner's Types when
 * none of it is still the owner's; it writes nothing, so "all" keeps
 * meaning all, Types created later included. Ids that aren't the owner's Types are
 * dropped either way. The session may hold an older shape, a [name => id]
 * map or the string '1'; the map is read as its ids, anything that isn't an
 * array as nothing remembered.
 */
function type_filter(mysqli $db, int $user_id, string $method, array $post, array &$session): array
{
  $types = array_column((new Types($db, $user_id))->all(), 'id', 'name');
  if (strtoupper($method) === 'POST') {
    $selected = type_filter_owned($types, $post['type'] ?? []);
    $session['type'] = $selected;
  } else {
    $selected = type_filter_owned($types, $session['type'] ?? []);
    if ($selected === []) {
      $selected = type_filter_owned($types, $types);
    }
  }
  return ['types' => $types, 'selected' => $selected];
}

/** The ids among $ids that are in $types, as strings, in $types order; none when $ids isn't an array. */
function type_filter_owned(array $types, $ids): array
{
  if (!is_array($ids)) {
    return [];
  }
  $ids = array_map('strval', array_filter(array_values($ids), 'is_scalar'));
  $owned = [];
  foreach ($types as $id) {
    if (in_array((string) $id, $ids, true)) {
      $owned[] = (string) $id;
    }
  }
  return $owned;
}

/**
 * The Type ids each type checkbox shortcut ticks, as strings in the owner's
 * Type order: games by item_game_type_ids(), the rest by their slugs. A
 * Type the owner lacks is skipped.
 */
function type_filter_shortcut_ids(array $types): array
{
  $ids = ['games' => item_game_type_ids($types)];
  foreach (TYPE_FILTER_SHORTCUT_SLUGS as $shortcut => $slugs) {
    $ids[$shortcut] = item_type_ids_where($types, function ($name) use ($slugs) {
      return in_array(type_filter_slug($name), $slugs, true);
    });
  }
  return $ids;
}

/** The Type's slug, as its checkbox id: the name with spaces turned into hyphens. */
function type_filter_slug($name): string
{
  return str_replace(' ', '-', (string) $name);
}
