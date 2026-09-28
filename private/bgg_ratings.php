<?php

/**
 * Another BoardGameGeek user's ratings and comments on the owner's items.
 * bin/import-bgg-ratings fills item_bgg_ratings from BGG, Edit Item can enter
 * one by hand, and the Items list reads it back as one column per BGG user.
 * The import never touches a hand entry; Request data on Edit Item replaces
 * one only when BGG has something for that item.
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

// A rating typed on Edit Item: the score, null when blank, or false when it
// is not a BGG score. BGG scores run 1 to 10 in steps as fine as 0.01, which
// the column holds.
function bgg_rating_from_input($raw) {
  $raw = trim((string) $raw);
  if ($raw === '') {
    return null;
  }
  if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $raw) || (float) $raw < 1 || (float) $raw > 10) {
    return false;
  }
  return (float) $raw;
}

// ['ok' => true, 'user' => ['id', 'username']] or ['ok' => false, 'error'].
function bgg_ratings_find_user($username, $get_json) {
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
  return ['ok' => true, 'user' => $bgg_user];
}

// Stores one BGG user's ['rating', 'comment', 'rated_at'] for one item,
// replacing what the item had. $manual marks the owner's own entry.
function bgg_ratings_store_item($conn, $user_id, $artifact_id, $bgg_username, array $entry, $manual = false) {
  $stmt = mysqli_prepare(
    $conn,
    "INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating, comment, rated_at, is_manual)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), rated_at = VALUES(rated_at), is_manual = VALUES(is_manual)"
  );
  $is_manual = $manual ? 1 : 0;
  mysqli_stmt_bind_param(
    $stmt,
    'iisdssi',
    $user_id,
    $artifact_id,
    $bgg_username,
    $entry['rating'],
    $entry['comment'],
    $entry['rated_at'],
    $is_manual
  );
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

// Removes one BGG user's row for one item. With $keep_manual, as when BGG
// simply has nothing, the owner's own entry stays.
function bgg_ratings_delete_item($conn, $user_id, $artifact_id, $bgg_username, $keep_manual = false) {
  $stmt = mysqli_prepare(
    $conn,
    'DELETE FROM item_bgg_ratings WHERE user_id = ? AND artifact_id = ? AND bgg_username = ?' . ($keep_manual ? ' AND is_manual = 0' : '')
  );
  mysqli_stmt_bind_param($stmt, 'iis', $user_id, $artifact_id, $bgg_username);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

// ['ok' => true, 'bgg_url'] for an owner's item, or ['ok' => false, 'error'].
function bgg_ratings_owned_item($conn, $user_id, $artifact_id) {
  $stmt = mysqli_prepare($conn, 'SELECT bgg_url FROM games WHERE id = ? AND user_id = ?');
  mysqli_stmt_bind_param($stmt, 'ii', $artifact_id, $user_id);
  mysqli_stmt_execute($stmt);
  $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$item) {
    return ['ok' => false, 'error' => 'Item not found.'];
  }
  return ['ok' => true, 'bgg_url' => (string) $item['bgg_url']];
}

// ['ok' => true, 'thing_id'] for an owner's item that links to a BGG thing,
// or ['ok' => false, 'error'] ready for Edit Item.
function bgg_ratings_linked_thing_id($conn, $user_id, $artifact_id) {
  $item = bgg_ratings_owned_item($conn, $user_id, $artifact_id);
  if (!$item['ok']) {
    return $item;
  }
  $thing_id = bgg_thing_id_from_url($item['bgg_url']);
  if ($thing_id <= 0) {
    return ['ok' => false, 'error' => 'Add a BoardGameGeek link to this item first.'];
  }
  return ['ok' => true, 'thing_id' => $thing_id];
}

/**
 * Fetches one BGG user's entry for one item and stores it, replacing even a
 * hand entry. Returns the entry (null when they have none, which clears an
 * imported row but keeps a hand entry), or false when BGG could not be
 * reached, which leaves the old row alone.
 */
function bgg_ratings_refresh_item($conn, $user_id, $artifact_id, $thing_id, array $bgg_user, $get_json) {
  $url = bgg_api_root() . '/collections?objectid=' . (int) $thing_id . '&objecttype=thing&userid=' . $bgg_user['id'];
  try {
    $json = bgg_fetch($url, $get_json);
  } catch (Throwable $e) {
    return false;
  }
  // Only a real {"items": [...]} answer may clear a rating. An empty or
  // garbled body, as BGG sends while it queues a request, counts as a failure.
  $data = json_decode((string) $json, true);
  if (!is_array($data) || !is_array($data['items'] ?? null)) {
    return false;
  }
  $entry = bgg_rating_from_collection_json($json);
  if ($entry === null) {
    bgg_ratings_delete_item($conn, $user_id, $artifact_id, $bgg_user['username'], true);
    return null;
  }
  bgg_ratings_store_item($conn, $user_id, $artifact_id, $bgg_user['username'], $entry);
  return $entry;
}

/**
 * Looks up $username's entry for every owner item with a BGG link and stores
 * what they rated or commented on. An item BGG answered with no entry loses
 * its old row; an item whose lookup failed keeps it. $pause_ms spaces the
 * requests out so a full collection does not hammer BGG.
 */
function bgg_ratings_import($conn, $user_id, $username, $get_json = null, $pause_ms = 250) {
  $user_id = (int) $user_id;
  $found = bgg_ratings_find_user($username, $get_json);
  if (!$found['ok']) {
    return $found;
  }
  $bgg_user = $found['user'];

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

  $had_rating = find_item_bgg_ratings($conn, array_keys($linked), $user_id);
  $result = ['ok' => true, 'username' => $bgg_user['username'], 'checked' => 0, 'imported' => 0, 'removed' => 0, 'failed' => 0];
  foreach ($linked as $artifact_id => $thing_id) {
    // The owner's own entry wins over whatever BGG has.
    if (!empty($had_rating[$artifact_id][$bgg_user['username']]['manual'])) {
      continue;
    }
    if ($result['checked'] > 0 && $pause_ms > 0) {
      usleep($pause_ms * 1000);
    }
    $result['checked']++;
    $entry = bgg_ratings_refresh_item($conn, $user_id, $artifact_id, $thing_id, $bgg_user, $get_json);
    if ($entry === false) {
      $result['failed']++;
    } elseif ($entry !== null) {
      $result['imported']++;
    } elseif (isset($had_rating[$artifact_id][$bgg_user['username']])) {
      $result['removed']++;
    }
  }

  // An item whose link was removed or no longer names a thing keeps no
  // imported rating. A hand entry needs no link, so it stays.
  $sql = 'DELETE FROM item_bgg_ratings WHERE user_id = ? AND bgg_username = ? AND is_manual = 0';
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

function bgg_overall_rating_store($conn, $user_id, $artifact_id, $rating) {
  $user_id = (int) $user_id;
  $artifact_id = (int) $artifact_id;
  if ($rating === null) {
    $stmt = mysqli_prepare($conn, 'UPDATE games SET BGG_Rat = NULL WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $artifact_id, $user_id);
  } else {
    $stmt = mysqli_prepare($conn, 'UPDATE games SET BGG_Rat = ? WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($stmt, 'sii', $rating, $artifact_id, $user_id);
  }
  $ok = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  return $ok;
}

/**
 * Copy BGG's average rating onto each of this owner's items that link to a
 * thing. Items that share a thing are fetched once. A failed or queued reply
 * leaves the stored average alone; a real answer with no average clears it.
 */
function bgg_overall_ratings_import($conn, $user_id, $get_json = null, $pause_ms = 250) {
  $user_id = (int) $user_id;
  $stmt = mysqli_prepare($conn, "SELECT id, bgg_url FROM games WHERE user_id = ? AND bgg_url IS NOT NULL AND bgg_url <> '' ORDER BY id");
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $by_thing = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $thing_id = bgg_thing_id_from_url($row['bgg_url']);
    if ($thing_id > 0) {
      $by_thing[$thing_id][] = (int) $row['id'];
    }
  }
  mysqli_stmt_close($stmt);

  $result = ['ok' => true, 'checked' => 0, 'imported' => 0, 'cleared' => 0, 'failed' => 0];
  foreach ($by_thing as $thing_id => $artifact_ids) {
    if ($result['checked'] > 0 && $pause_ms > 0) {
      usleep((int) $pause_ms * 1000);
    }
    $result['checked'] += count($artifact_ids);
    $url = bgg_api_root() . '/dynamicinfo?objectid=' . (int) $thing_id . '&objecttype=thing';
    try {
      $json = bgg_fetch($url, $get_json);
    } catch (Throwable $e) {
      $result['failed'] += count($artifact_ids);
      continue;
    }
    $rating = bgg_overall_rating_from_dynamic_json($json);
    if ($rating === false) {
      $result['failed'] += count($artifact_ids);
      continue;
    }
    foreach ($artifact_ids as $artifact_id) {
      bgg_overall_rating_store($conn, $user_id, $artifact_id, $rating);
      if ($rating === null) {
        $result['cleared']++;
      } else {
        $result['imported']++;
      }
    }
  }
  return $result;
}

/**
 * Edit Item's "Request <user> data": one item's entry, fetched now. The item
 * must belong to the owner and link to a BGG thing. On success 'message' says
 * what BGG had, ready for the page.
 */
function bgg_ratings_import_item($conn, $user_id, $artifact_id, $username, $get_json = null) {
  $user_id = (int) $user_id;
  $artifact_id = (int) $artifact_id;
  $linked = bgg_ratings_linked_thing_id($conn, $user_id, $artifact_id);
  if (!$linked['ok']) {
    return $linked;
  }
  $thing_id = $linked['thing_id'];

  $found = bgg_ratings_find_user($username, $get_json);
  if (!$found['ok']) {
    return $found;
  }
  $bgg_user = $found['user'];
  $entry = bgg_ratings_refresh_item($conn, $user_id, $artifact_id, $thing_id, $bgg_user, $get_json);
  if ($entry === false) {
    return ['ok' => false, 'error' => 'Could not reach BoardGameGeek.'];
  }
  if ($entry === null) {
    $kept = find_item_bgg_ratings($conn, [$artifact_id], $user_id)[$artifact_id][$bgg_user['username']] ?? null;
    $message = $bgg_user['username'] . ' has not rated or commented on this item on BoardGameGeek'
      . ($kept === null ? '.' : ', so your entry stays.');
  } elseif ($entry['rating'] === null) {
    $message = $bgg_user['username'] . ' commented on this item without rating it.';
  } else {
    $message = $bgg_user['username'] . ' rated it ' . bgg_score_text($entry['rating']) . ' out of 10.';
  }
  return ['ok' => true, 'username' => $bgg_user['username'], 'message' => $message];
}

/**
 * Edit Item's rating editor: the owner's own rating and comment for an
 * imported BGG user on one item, with or without a BGG link or an earlier
 * rating. $username must be a reviewer the owner already imported. A blank
 * rating or comment stores none; both blank removes the row. The import
 * leaves the entry alone; "Request <user> data" replaces it only when BGG has
 * an entry for the item.
 */
function bgg_ratings_save_item($conn, $user_id, $artifact_id, $username, $rating, $comment) {
  $user_id = (int) $user_id;
  $artifact_id = (int) $artifact_id;
  $owned = bgg_ratings_owned_item($conn, $user_id, $artifact_id);
  if (!$owned['ok']) {
    return $owned;
  }
  $reviewer = null;
  foreach (item_bgg_reviewers($conn, $user_id) as $known) {
    if (strcasecmp($known, trim((string) $username)) === 0) {
      $reviewer = $known;
    }
  }
  if ($reviewer === null) {
    return ['ok' => false, 'error' => 'No imported BoardGameGeek ratings from ' . trim((string) $username) . '.'];
  }

  $rating = bgg_rating_from_input($rating);
  if ($rating === false) {
    return ['ok' => false, 'error' => 'A rating is a number from 1 to 10.'];
  }
  $comment = trim((string) $comment);
  $comment = $comment === '' ? null : $comment;

  $whose = $reviewer . (substr($reviewer, -1) === 's' ? "'" : "'s");
  if ($rating === null && $comment === null) {
    bgg_ratings_delete_item($conn, $user_id, $artifact_id, $reviewer);
    return ['ok' => true, 'message' => 'Removed ' . $whose . ' rating and comment.'];
  }
  // An edited score is the owner's, not BGG's, so it carries no BGG rating date.
  bgg_ratings_store_item($conn, $user_id, $artifact_id, $reviewer, ['rating' => $rating, 'comment' => $comment, 'rated_at' => null], true);
  return ['ok' => true, 'message' => 'Saved ' . $whose . ' rating and comment.'];
}

// [artifact_id => [bgg_username => ['rating', 'comment', 'url', 'manual']]] for the owner.
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
    "SELECT r.artifact_id, r.bgg_username, r.rating, r.comment, r.is_manual, g.bgg_url
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
      'manual' => (bool) $row['is_manual'],
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

// A BGG score as people write it: 9.5 reads "9.5", 8 reads "8".
function bgg_score_text($score) {
  return rtrim(rtrim(number_format((float) $score, 2, '.', ''), '0'), '.');
}

// One item's imported ratings, as find_item_bgg_ratings() keys them, for the
// item pages: each reviewer's score in words, then their comment in full.
function item_bgg_ratings_html(array $ratings_by_reviewer) {
  $html = '';
  foreach ($ratings_by_reviewer as $reviewer => $rating) {
    $source = empty($rating['manual']) ? ' on BoardGameGeek' : ' (entered by hand)';
    $caption = $rating['rating'] === null
      ? $reviewer . ' commented' . $source
      : $reviewer . ' rated it ' . bgg_score_text($rating['rating']) . ' out of 10' . $source;
    $html .= '<figure class="item-bgg-rating">';
    if ((string) $rating['comment'] !== '') {
      $html .= '<blockquote class="bgg-rating-comment">' . h($rating['comment']) . '</blockquote>';
    }
    $html .= '<figcaption>' . h($caption) . '</figcaption></figure>';
  }
  return $html;
}

function with_item_bgg_ratings($conn, array $items, $user_id) {
  $ratings = find_item_bgg_ratings($conn, array_column($items, 'id'), $user_id);
  foreach ($items as &$item) {
    $item['bgg_ratings'] = $ratings[(int) ($item['id'] ?? 0)] ?? [];
  }
  unset($item);
  return $items;
}
