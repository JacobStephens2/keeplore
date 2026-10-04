<?php

/**
 * Free-form tags on items. Labels the collection user attaches so questions
 * like "beach-safe" are a filter, not a derivation from player-count
 * columns. Distinct from BGG-imported taxonomy. Scoped per user.
 *
 * Here: the tag rules and the read that attaches tags to rows. The Items
 * module owns storing an Item's tags and filtering by tag.
 */

function normalize_item_tag($raw) {
  $tag = strtolower(trim((string) $raw));
  $tag = preg_replace('/\s+/u', ' ', $tag);
  if ($tag === '') {
    return null;
  }
  if (mb_strlen($tag) > 64) {
    $tag = mb_substr($tag, 0, 64);
  }
  return $tag;
}

function parse_item_tags_input($raw) {
  if (is_string($raw)) {
    $raw = $raw === '' ? [] : explode(',', $raw);
  }
  if (!is_array($raw)) {
    return [];
  }
  $tags = [];
  foreach ($raw as $value) {
    $tag = normalize_item_tag($value);
    if ($tag !== null) {
      $tags[$tag] = $tag;
    }
  }
  $tags = array_values($tags);
  sort($tags, SORT_STRING);
  return $tags;
}

function attach_item_tags(array $items, array $tags_by_artifact_id) {
  return array_map(function (array $item) use ($tags_by_artifact_id) {
    $item['tags'] = $tags_by_artifact_id[(int) ($item['id'] ?? 0)] ?? [];
    return $item;
  }, $items);
}

/** The $items rows, each with the owner's tags on it as a sorted list, empty when it has none. */
function with_item_tags($conn, array $items, $user_id) {
  $ids = array_values(array_unique(array_filter(array_map(fn (array $item) => (int) ($item['id'] ?? 0), $items))));
  if ($ids === []) {
    return attach_item_tags($items, []);
  }
  $stmt = mysqli_prepare(
    $conn,
    'SELECT artifact_id, tag
     FROM item_tags
     WHERE artifact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND user_id = ?
     ORDER BY tag ASC'
  );
  mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)) . 'i', ...[...$ids, (int) $user_id]);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $tags_by_artifact_id = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $tags_by_artifact_id[(int) $row['artifact_id']][] = $row['tag'];
  }
  mysqli_stmt_close($stmt);
  return attach_item_tags($items, $tags_by_artifact_id);
}
