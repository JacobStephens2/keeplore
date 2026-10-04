<?php

/**
 * The pure helpers beside the BGG ratings module (classes/BggRatings.php):
 * reading a thing id from an item's link, BGG's users and collection JSON,
 * a rating typed on Edit Item, and a score or an item's ratings for the page.
 * They hold no owner state; the module holds the SQL and the BGG fetches.
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

// A BGG score as people write it: 9.5 reads "9.5", 8 reads "8".
function bgg_score_text($score) {
  return rtrim(rtrim(number_format((float) $score, 2, '.', ''), '0'), '.');
}

// One item's imported ratings, as BggRatings::forItems() keys them, for the
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
