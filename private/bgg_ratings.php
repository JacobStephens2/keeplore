<?php

/**
 * Another BoardGameGeek user's ratings and comments on the owner's items.
 * bin/import-bgg-ratings fills item_bgg_ratings from BGG; the Items list
 * reads it back as one column per BGG user.
 */

require_once __DIR__ . '/bgg_lookup.php';

// The BGG "thing" an item's link names, or 0. Family and person pages such as
// rpggeek.com/rpg/... are not things and carry no ratings.
function bgg_thing_id_from_url($url) {
  $url = normalize_item_bgg_url($url);
  if ($url === '' || !preg_match('#^https://[^/]+/(boardgame|boardgameexpansion|boardgameaccessory|videogame|rpgitem)/(\d+)(/|$)#i', $url, $match)) {
    return 0;
  }
  return (int) $match[2];
}

function bgg_user_from_users_json($json, $username) {
  $users = json_decode((string) $json, true);
  if (!is_array($users)) {
    return null;
  }
  foreach ($users as $user) {
    if (!is_array($user) || !isset($user['userid'], $user['username'])) {
      continue;
    }
    if (strcasecmp((string) $user['username'], trim((string) $username)) === 0 && (int) $user['userid'] > 0) {
      return ['id' => (int) $user['userid'], 'username' => (string) $user['username']];
    }
  }
  return null;
}

// One user's collection entry for one thing, reduced to what the Items list
// shows. Null when the user neither rated nor commented on it.
function bgg_rating_from_collection_json($json) {
  $data = json_decode((string) $json, true);
  $entry = $data['items'][0] ?? null;
  if (!is_array($entry)) {
    return null;
  }
  $rating = is_numeric($entry['rating'] ?? null) && (float) $entry['rating'] > 0
    ? (float) $entry['rating']
    : null;
  $comment = trim((string) ($entry['textfield']['comment']['value'] ?? ''));
  if ($rating === null && $comment === '') {
    return null;
  }
  $rated_at = (string) ($entry['rating_tstamp'] ?? '');
  return [
    'rating' => $rating,
    'comment' => $comment === '' ? null : $comment,
    'rated_at' => ($rating !== null && preg_match('/^[1-9]\d{3}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rated_at)) ? $rated_at : null,
  ];
}

/**
 * Looks up $username's entry for every owner item with a BGG link and stores
 * what they rated or commented on. An item BGG answered with no entry loses
 * its old row; an item whose lookup failed keeps it. $pause_ms spaces the
 * requests out so a full collection does not hammer BGG.
 */
function bgg_ratings_import($conn, $user_id, $username, $get_json = null, $pause_ms = 250) {
  $user_id = (int) $user_id;
  $username = trim((string) $username);
  try {
    $bgg_user = bgg_user_from_users_json(
      bgg_fetch(bgg_api_root() . '/users?username=' . rawurlencode($username), $get_json),
      $username
    );
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Could not reach BoardGameGeek.'];
  }
  if ($bgg_user === null) {
    return ['ok' => false, 'error' => 'No BoardGameGeek user named ' . $username . '.'];
  }

  $stmt = mysqli_prepare($conn, "SELECT id, bgg_url FROM games WHERE user_id = ? AND bgg_url IS NOT NULL AND bgg_url <> '' ORDER BY id");
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $linked = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $thing_id = bgg_thing_id_from_url($row['bgg_url']);
    if ($thing_id > 0) {
      $linked[(int) $row['id']] = $thing_id;
    }
  }
  mysqli_stmt_close($stmt);

  $upsert = mysqli_prepare(
    $conn,
    "INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating, comment, rated_at)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), rated_at = VALUES(rated_at)"
  );
  $delete = mysqli_prepare($conn, 'DELETE FROM item_bgg_ratings WHERE user_id = ? AND artifact_id = ? AND bgg_username = ?');
  $result = ['ok' => true, 'username' => $bgg_user['username'], 'checked' => 0, 'imported' => 0, 'removed' => 0, 'failed' => 0];
  foreach ($linked as $artifact_id => $thing_id) {
    if ($result['checked'] > 0 && $pause_ms > 0) {
      usleep($pause_ms * 1000);
    }
    $result['checked']++;
    $url = bgg_api_root() . '/collections?objectid=' . $thing_id . '&objecttype=thing&userid=' . $bgg_user['id'];
    try {
      $entry = bgg_rating_from_collection_json(bgg_fetch($url, $get_json));
    } catch (Throwable $e) {
      $result['failed']++;
      continue;
    }
    if ($entry === null) {
      mysqli_stmt_bind_param($delete, 'iis', $user_id, $artifact_id, $bgg_user['username']);
      mysqli_stmt_execute($delete);
      $result['removed'] += mysqli_stmt_affected_rows($delete);
      continue;
    }
    mysqli_stmt_bind_param(
      $upsert,
      'iisdss',
      $user_id,
      $artifact_id,
      $bgg_user['username'],
      $entry['rating'],
      $entry['comment'],
      $entry['rated_at']
    );
    mysqli_stmt_execute($upsert);
    $result['imported']++;
  }
  mysqli_stmt_close($upsert);
  mysqli_stmt_close($delete);

  // An item whose link was removed or no longer names a thing keeps no rating.
  $sql = 'DELETE FROM item_bgg_ratings WHERE user_id = ? AND bgg_username = ?';
  $params = [$user_id, $bgg_user['username']];
  if ($linked !== []) {
    $sql .= ' AND artifact_id NOT IN (' . implode(',', array_fill(0, count($linked), '?')) . ')';
    $params = array_merge($params, array_keys($linked));
  }
  $unlinked = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($unlinked, 'is' . str_repeat('i', count($linked)), ...$params);
  mysqli_stmt_execute($unlinked);
  $result['removed'] += mysqli_stmt_affected_rows($unlinked);
  mysqli_stmt_close($unlinked);
  return $result;
}

// [artifact_id => [bgg_username => ['rating', 'comment', 'url']]] for the owner.
function find_item_bgg_ratings($conn, array $artifact_ids, $user_id) {
  $artifact_ids = array_values(array_filter(array_unique(array_map('intval', $artifact_ids))));
  if ($artifact_ids === []) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($artifact_ids), '?'));
  $params = $artifact_ids;
  $params[] = (int) $user_id;
  $stmt = mysqli_prepare(
    $conn,
    "SELECT r.artifact_id, r.bgg_username, r.rating, r.comment, g.bgg_url
     FROM item_bgg_ratings r
     JOIN games g ON g.id = r.artifact_id AND g.user_id = r.user_id
     WHERE r.artifact_id IN ({$placeholders}) AND r.user_id = ?
     ORDER BY r.artifact_id, r.bgg_username"
  );
  mysqli_stmt_bind_param($stmt, str_repeat('i', count($params)), ...$params);
  mysqli_stmt_execute($stmt);
  $ratings = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $ratings[(int) $row['artifact_id']][$row['bgg_username']] = [
      'rating' => $row['rating'] === null ? null : (float) $row['rating'],
      'comment' => $row['comment'],
      'url' => (string) $row['bgg_url'],
    ];
  }
  mysqli_stmt_close($stmt);
  return $ratings;
}

/** The BGG users whose ratings the owner has imported, alphabetically. */
function item_bgg_reviewers($conn, $user_id) {
  // Through games, so a deleted item's rating does not keep an empty column.
  $stmt = mysqli_prepare(
    $conn,
    'SELECT DISTINCT r.bgg_username
     FROM item_bgg_ratings r
     JOIN games g ON g.id = r.artifact_id AND g.user_id = r.user_id
     WHERE r.user_id = ?
     ORDER BY r.bgg_username'
  );
  $user_id = (int) $user_id;
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $reviewers = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $reviewers[] = $row['bgg_username'];
  }
  mysqli_stmt_close($stmt);
  return $reviewers;
}

function with_item_bgg_ratings($conn, array $items, $user_id) {
  $ratings = find_item_bgg_ratings($conn, array_column($items, 'id'), $user_id);
  foreach ($items as &$item) {
    $item['bgg_ratings'] = $ratings[(int) ($item['id'] ?? 0)] ?? [];
  }
  unset($item);
  return $items;
}
