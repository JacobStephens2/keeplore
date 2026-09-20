<?php

/**
 * Free-form tags on items. Labels the collection user attaches so questions
 * like "beach-safe" are a filter, not a derivation from player-count
 * columns. Distinct from BGG-imported taxonomy. Scoped per user.
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
  $attached = [];
  foreach ($items as $item) {
    if (is_object($item)) {
      $record = clone $item;
      $id = (int) ($record->id ?? 0);
      $record->tags = $tags_by_artifact_id[$id] ?? [];
      $attached[] = $record;
      continue;
    }
    $id = (int) ($item['id'] ?? 0);
    $item['tags'] = $tags_by_artifact_id[$id] ?? [];
    $attached[] = $item;
  }
  return $attached;
}

function find_item_tags_for_artifacts($conn, array $artifact_ids, $user_id) {
  $artifact_ids = array_values(array_unique(array_map('intval', $artifact_ids)));
  $artifact_ids = array_values(array_filter($artifact_ids));
  if ($artifact_ids === []) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($artifact_ids), '?'));
  $types = str_repeat('i', count($artifact_ids)) . 'i';
  $params = $artifact_ids;
  $params[] = (int) $user_id;
  $stmt = mysqli_prepare(
    $conn,
    "SELECT artifact_id, tag
     FROM item_tags
     WHERE artifact_id IN ({$placeholders}) AND user_id = ?
     ORDER BY tag ASC"
  );
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $tags_by_artifact_id = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $artifact_id = (int) $row['artifact_id'];
    if (!isset($tags_by_artifact_id[$artifact_id])) {
      $tags_by_artifact_id[$artifact_id] = [];
    }
    $tags_by_artifact_id[$artifact_id][] = $row['tag'];
  }
  mysqli_stmt_close($stmt);
  return $tags_by_artifact_id;
}

function with_item_tags($conn, array $items, $user_id) {
  $ids = [];
  foreach ($items as $item) {
    $ids[] = is_object($item) ? (int) ($item->id ?? 0) : (int) ($item['id'] ?? 0);
  }
  return attach_item_tags($items, find_item_tags_for_artifacts($conn, $ids, $user_id));
}

function persist_and_attach_item_tags($conn, $item, $user_id, $tags = null) {
  $id = is_object($item) ? (int) ($item->id ?? 0) : (int) ($item['id'] ?? 0);
  if ($user_id && $tags !== null) {
    replace_item_tags($conn, $id, $user_id, $tags);
  }
  if ($user_id) {
    return with_item_tags($conn, [$item], $user_id)[0];
  }
  return $item;
}

function replace_item_tags($conn, $artifact_id, $user_id, $tags) {
  $artifact_id = (int) $artifact_id;
  $user_id = (int) $user_id;
  $tags = parse_item_tags_input($tags);

  $item_stmt = mysqli_prepare($conn, "SELECT id FROM games WHERE id = ? AND user_id = ? LIMIT 1");
  mysqli_stmt_bind_param($item_stmt, 'ii', $artifact_id, $user_id);
  mysqli_stmt_execute($item_stmt);
  $item_row = mysqli_fetch_assoc(mysqli_stmt_get_result($item_stmt));
  mysqli_stmt_close($item_stmt);
  if (!$item_row) {
    return false;
  }

  $delete = mysqli_prepare($conn, "DELETE FROM item_tags WHERE artifact_id = ? AND user_id = ?");
  mysqli_stmt_bind_param($delete, 'ii', $artifact_id, $user_id);
  mysqli_stmt_execute($delete);
  mysqli_stmt_close($delete);

  if ($tags === []) {
    return true;
  }

  $insert = mysqli_prepare(
    $conn,
    "INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (?, ?, ?)"
  );
  foreach ($tags as $tag) {
    mysqli_stmt_bind_param($insert, 'iis', $user_id, $artifact_id, $tag);
    mysqli_stmt_execute($insert);
  }
  mysqli_stmt_close($insert);
  return true;
}

function artifact_ids_with_tag($conn, $user_id, $tag) {
  $normalized = normalize_item_tag($tag);
  if ($normalized === null) {
    return [];
  }
  $user_id = (int) $user_id;
  $stmt = mysqli_prepare(
    $conn,
    "SELECT artifact_id
     FROM item_tags
     WHERE user_id = ? AND tag = ?
     ORDER BY artifact_id ASC"
  );
  mysqli_stmt_bind_param($stmt, 'is', $user_id, $normalized);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $ids = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $ids[] = (int) $row['artifact_id'];
  }
  mysqli_stmt_close($stmt);
  return $ids;
}

function item_tag_user_filter($tag, $user_id) {
  $normalized = normalize_item_tag($tag);
  if ($normalized === null) {
    return ['sql' => '', 'types' => '', 'params' => []];
  }
  return [
    'sql' => " AND games.id IN (SELECT artifact_id FROM item_tags WHERE user_id = ? AND tag = ?)",
    'types' => 'is',
    'params' => [(int) $user_id, $normalized],
  ];
}

function delete_item_tags_for_artifact($conn, $artifact_id, $user_id = null) {
  $artifact_id = (int) $artifact_id;
  if ($user_id === null) {
    $stmt = mysqli_prepare($conn, "DELETE FROM item_tags WHERE artifact_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $artifact_id);
  } else {
    $user_id = (int) $user_id;
    $stmt = mysqli_prepare($conn, "DELETE FROM item_tags WHERE artifact_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $artifact_id, $user_id);
  }
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}
