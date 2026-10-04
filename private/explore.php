<?php

require_once __DIR__ . '/classes/Items.php';

/**
 * Explore pages: Candidates and Items by characteristic. Each answer is the
 * owner's Items::list rows (so each carries last_use and type_name), of the
 * Types in $type_ids (none when it is empty), filtered and ordered here for
 * its page. Callers do not query or shape rows themselves.
 */

/** Items by characteristic's default order: [column, 'text' or 'number', 1 ascending or -1 descending]. */
const EXPLORE_CHARACTERISTIC_ORDER = [
  ['SS', 'text', 1],
  ['MxT', 'number', 1],
  ['MnT', 'number', 1],
  ['Age', 'number', 1],
  ['FavCt', 'number', -1],
  ['BGG_Rat', 'number', -1],
];

/**
 * The owner's items with a Candidate (not blank and not '0'), ordered by
 * type name, Candidate and Title, ignoring case. $options may hold 'online'
 * ('only' or 'hide'; anything else shows all) and 'exclude_names', names
 * whose Candidates are left out. Both match a substring, ignoring case.
 */
function candidate_items(mysqli $db, int $user_id, array $type_ids, array $options = []): array
{
  $online = $options['online'] ?? null;
  $exclude_names = array_filter(
    array_map('strval', $options['exclude_names'] ?? []),
    fn (string $name) => $name !== ''
  );
  $rows = array_filter(
    (new Items($db, $user_id))->list(['type_ids' => $type_ids]),
    function (array $row) use ($online, $exclude_names) {
      $candidate = (string) ($row['Candidate'] ?? '');
      if (explore_is_blank($candidate) || $candidate === '0') {
        return false;
      }
      $is_online = stripos($candidate, 'online') !== false;
      if (($online === 'only' && !$is_online) || ($online === 'hide' && $is_online)) {
        return false;
      }
      foreach ($exclude_names as $name) {
        if (stripos($candidate, $name) !== false) {
          return false;
        }
      }
      return true;
    }
  );
  return explore_sorted($rows, [['type_name', 'text', 1], ['Candidate', 'text', 1], ['Title', 'text', 1]]);
}

/**
 * The owner's items with a sweet spot, in EXPLORE_CHARACTERISTIC_ORDER.
 * $options may hold 'kept' (true lists only Kept items) and 'order'
 * ('fav_count' puts fav count, most first, ahead of the default order).
 */
function characteristic_items(mysqli $db, int $user_id, array $type_ids, array $options = []): array
{
  $filters = ['type_ids' => $type_ids];
  if (($options['kept'] ?? false) === true) {
    $filters['kept'] = true;
  }
  $rows = array_filter(
    (new Items($db, $user_id))->list($filters),
    fn (array $row) => !explore_is_blank($row['SS'] ?? null)
  );
  $order = EXPLORE_CHARACTERISTIC_ORDER;
  if (($options['order'] ?? null) === 'fav_count') {
    array_unshift($order, ['FavCt', 'number', -1]);
  }
  return explore_sorted($rows, $order);
}

/**
 * $rows sorted by each [column, kind, direction] in turn, ties keeping
 * their order. Text compares ignoring case; a number that isn't numeric
 * counts as missing. Missing values come first ascending and last
 * descending, as in SQL.
 */
function explore_sorted(array $rows, array $order): array
{
  $rows = array_values($rows);
  usort($rows, function (array $a, array $b) use ($order) {
    foreach ($order as [$column, $kind, $direction]) {
      $x = explore_sort_value($a[$column] ?? null, $kind);
      $y = explore_sort_value($b[$column] ?? null, $kind);
      $compared = match (true) {
        $x === null || $y === null => ($x !== null) <=> ($y !== null),
        $kind === 'text' => strcmp($x, $y),
        default => $x <=> $y,
      };
      if ($compared !== 0) {
        return $compared * $direction;
      }
    }
    return 0;
  });
  return $rows;
}

function explore_sort_value($value, string $kind): string|float|null
{
  if ($kind === 'number') {
    return is_numeric($value) ? (float) $value : null;
  }
  return $value === null ? null : strtolower((string) $value);
}

function explore_is_blank($value): bool
{
  return trim((string) ($value ?? '')) === '';
}
