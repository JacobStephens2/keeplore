<?php

/**
 * Search BGG: find BoardGameGeek games by what the community voted, the
 * player counts it rates Best and its community age.
 * BGG has no search on those polls, so bin/refresh-bgg-poll-index copies the
 * polls of each subdomain's top-ranked games into bgg_poll_games, and the
 * search reads that. The index describes BGG, so every owner shares it.
 */

require_once __DIR__ . '/bgg_lookup.php';
require_once __DIR__ . '/classes/BggRatings.php';

// An open-ended Best such as "9+" counts as Best at every count up to this.
const BGG_POLL_OPEN_BEST_UP_TO = 20;

// Search BGG lists at most this many games.
const BGG_POLL_SEARCH_LIMIT = 200;

// BGG's game subdomains, each a family whose linked games list by rank.
function bgg_poll_subdomains() {
  return [
    5499 => 'Family',
    5498 => 'Party',
    4665 => "Children's",
    5497 => 'Strategy',
    5496 => 'Thematic',
    4666 => 'Abstract',
    4667 => 'Customizable',
    4664 => 'Wargames',
  ];
}

function bgg_poll_listing_url($family_id, $page) {
  return bgg_api_root() . '/geekitem/linkeditems?linkdata_index=boardgame&objectid=' . (int) $family_id
    . '&objecttype=family&subtype=boardgamesubdomain&sort=rank&pageid=' . (int) $page . '&showcount=50';
}

// One page of a subdomain's ranked games: each thing once, with what the
// search shows. BGG writes "0" for an unranked game or a missing year. False
// when the body is not a list, as while BGG queues a request.
function bgg_poll_listing_from_json($json) {
  $data = json_decode((string) $json, true);
  if (!is_array($data) || !is_array($data['items'] ?? null)) {
    return false;
  }
  $games = [];
  foreach ($data['items'] as $item) {
    if (!is_array($item) || ($item['objecttype'] ?? '') !== 'thing') {
      continue;
    }
    $id = (int) ($item['objectid'] ?? 0);
    $name = trim((string) ($item['name'] ?? ''));
    if ($id <= 0 || $name === '' || isset($games[$id])) {
      continue;
    }
    $year = (int) ($item['yearpublished'] ?? 0);
    $rank = (int) ($item['rank'] ?? 0);
    $average = (float) ($item['average'] ?? 0);
    $image = normalize_item_image_url($item['images']['thumb'] ?? '');
    $games[$id] = [
      'thing_id' => $id,
      'name' => $name,
      'year_published' => $year !== 0 ? $year : null,
      'bgg_rank' => $rank > 0 ? $rank : null,
      'average' => $average > 0 ? $average : null,
      'users_rated' => max(0, (int) ($item['usersrated'] ?? 0)),
      'image_url' => $image !== '' ? $image : null,
    ];
  }
  return array_values($games);
}

/**
 * The polls in a dynamicinfo body: ['best' => player counts, 'best_text' as
 * people write it ("6, 8", "6-7", "9+"), 'player_votes' => everyone who
 * voted on the player-count poll, 'community_age' => int or null]. False when
 * the body is not that answer, as while BGG queues a request. BGG's JSON
 * gives no vote count for the age poll.
 */
function bgg_poll_results_from_dynamic_json($json) {
  $data = json_decode((string) $json, true);
  $polls = $data['item']['polls'] ?? null;
  if (!is_array($polls)) {
    return false;
  }
  $best = [];
  $parts = [];
  $ranges = $polls['userplayers']['best'] ?? [];
  $ranges = is_array($ranges) ? array_filter($ranges, 'is_array') : [];
  // Smallest first, so an open-ended range is always the last part of
  // best_text; the search's open-ended filter reads it from there.
  usort($ranges, fn ($a, $b) => (int) ($a['min'] ?? 0) <=> (int) ($b['min'] ?? 0));
  foreach ($ranges as $range) {
    $min = (int) ($range['min'] ?? 0);
    if ($min <= 0) {
      continue;
    }
    $open = !isset($range['max']);
    $max = $open ? max($min, BGG_POLL_OPEN_BEST_UP_TO) : max($min, (int) $range['max']);
    $best = array_merge($best, range($min, $max));
    $parts[] = $open ? $min . '+' : ($min === $max ? (string) $min : $min . '-' . $max);
  }
  $best = array_values(array_unique($best));
  sort($best);
  $age = bgg_community_age_from_polls(['polls' => $polls]);
  return [
    'best' => $best,
    'best_text' => implode(', ', $parts),
    'player_votes' => max(0, (int) ($polls['userplayers']['totalvotes'] ?? 0)),
    'community_age' => $age === null ? null : (int) $age,
  ];
}

// The search form: a Best player count, a youngest age, the fewest
// player-poll votes worth trusting, and whether to leave out games Best at
// the count only through an open-ended vote. Anything else reads as not set.
function bgg_poll_search_filters(array $input) {
  $number = function ($key, $min, $max) use ($input) {
    $value = $input[$key] ?? '';
    if (!is_string($value) || !preg_match('/^\d{1,5}$/', trim($value))) {
      return null;
    }
    $value = (int) $value;
    return $value >= $min && $value <= $max ? $value : null;
  };
  return [
    'best' => $number('best', 1, 99),
    'age' => $number('age', 1, 99),
    'min_votes' => $number('min_votes', 0, 99999) ?? 0,
    'skip_open' => ($input['skip_open'] ?? '') === '1',
  ];
}

/**
 * Indexed games matching bgg_poll_search_filters(), best BGG rank first:
 * Best with 'best' players, rated by the community for 'age' or younger,
 * with at least 'min_votes' player-poll votes. Either filter works alone.
 * With 'skip_open', a game Best at 'best' only because an open-ended vote
 * such as "4+" runs past its count is left out; "9+" still counts at 9.
 */
function bgg_poll_search($conn, array $filters, $limit = BGG_POLL_SEARCH_LIMIT) {
  $sql = 'SELECT g.thing_id, g.name, g.year_published, g.bgg_rank, g.average, g.users_rated, g.image_url,
            g.subdomains, g.best_players, g.player_votes, g.community_age
          FROM bgg_poll_games g';
  $where = ['g.polls_fetched_at IS NOT NULL', 'g.player_votes >= ?'];
  $types = 'i';
  $params = [(int) ($filters['min_votes'] ?? 0)];
  if (($filters['best'] ?? null) !== null) {
    $sql .= ' JOIN bgg_poll_best_players b ON b.thing_id = g.thing_id AND b.players = ?';
    $types = 'i' . $types;
    array_unshift($params, (int) $filters['best']);
    if (!empty($filters['skip_open'])) {
      // An open-ended vote can only be the last part of best_players ("3, 4+").
      $where[] = "NOT (g.best_players LIKE '%+' AND b.players > CAST(REPLACE(SUBSTRING_INDEX(g.best_players, ' ', -1), '+', '') AS UNSIGNED))";
    }
  }
  if (($filters['age'] ?? null) !== null) {
    $where[] = 'g.community_age <= ?';
    $types .= 'i';
    $params[] = (int) $filters['age'];
  }
  $sql .= ' WHERE ' . implode(' AND ', $where)
    . ' ORDER BY g.bgg_rank IS NULL, g.bgg_rank, g.player_votes DESC LIMIT ' . max(1, (int) $limit);
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $games = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $games[] = [
      'thing_id' => (int) $row['thing_id'],
      'name' => $row['name'],
      'year_published' => $row['year_published'] === null ? null : (int) $row['year_published'],
      'bgg_rank' => $row['bgg_rank'] === null ? null : (int) $row['bgg_rank'],
      'average' => $row['average'] === null ? null : (float) $row['average'],
      'users_rated' => (int) $row['users_rated'],
      'image_url' => $row['image_url'],
      'subdomains' => $row['subdomains'],
      'best_players' => $row['best_players'],
      'player_votes' => (int) $row['player_votes'],
      'community_age' => $row['community_age'] === null ? null : (int) $row['community_age'],
      'url' => bgg_canonical_url((int) $row['thing_id'], 'BGG'),
    ];
  }
  mysqli_stmt_close($stmt);
  return $games;
}

function bgg_poll_store_listing($conn, array $game, $subdomains) {
  $stmt = mysqli_prepare(
    $conn,
    'INSERT INTO bgg_poll_games (thing_id, name, year_published, bgg_rank, average, users_rated, image_url, subdomains)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), year_published = VALUES(year_published), bgg_rank = VALUES(bgg_rank),
       average = VALUES(average), users_rated = VALUES(users_rated), image_url = VALUES(image_url), subdomains = VALUES(subdomains)'
  );
  mysqli_stmt_bind_param(
    $stmt,
    'isiidiss',
    $game['thing_id'],
    $game['name'],
    $game['year_published'],
    $game['bgg_rank'],
    $game['average'],
    $game['users_rated'],
    $game['image_url'],
    $subdomains
  );
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

function bgg_poll_store_results($conn, $thing_id, array $polls) {
  $thing_id = (int) $thing_id;
  mysqli_begin_transaction($conn);
  try {
    bgg_poll_write_results($conn, $thing_id, $polls);
    mysqli_commit($conn);
  } catch (Throwable $e) {
    mysqli_rollback($conn);
    throw $e;
  }
}

function bgg_poll_write_results($conn, $thing_id, array $polls) {
  $stmt = mysqli_prepare(
    $conn,
    'UPDATE bgg_poll_games SET best_players = ?, player_votes = ?, community_age = ?, polls_fetched_at = NOW() WHERE thing_id = ?'
  );
  mysqli_stmt_bind_param($stmt, 'siii', $polls['best_text'], $polls['player_votes'], $polls['community_age'], $thing_id);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

  $delete = mysqli_prepare($conn, 'DELETE FROM bgg_poll_best_players WHERE thing_id = ?');
  mysqli_stmt_bind_param($delete, 'i', $thing_id);
  mysqli_stmt_execute($delete);
  mysqli_stmt_close($delete);

  $insert = mysqli_prepare($conn, 'INSERT INTO bgg_poll_best_players (thing_id, players) VALUES (?, ?)');
  foreach ($polls['best'] as $players) {
    if ($players > 255) {
      continue;
    }
    mysqli_stmt_bind_param($insert, 'ii', $thing_id, $players);
    mysqli_stmt_execute($insert);
  }
  mysqli_stmt_close($insert);
}

// [thing_id => true] for games whose polls were fetched within $days.
function bgg_poll_fresh_ids($conn, $days) {
  $stmt = mysqli_prepare($conn, 'SELECT thing_id FROM bgg_poll_games WHERE polls_fetched_at >= NOW() - INTERVAL ? DAY');
  $days = (int) $days;
  mysqli_stmt_bind_param($stmt, 'i', $days);
  mysqli_stmt_execute($stmt);
  $fresh = [];
  foreach (mysqli_stmt_get_result($stmt) as $row) {
    $fresh[(int) $row['thing_id']] = true;
  }
  mysqli_stmt_close($stmt);
  return $fresh;
}

/**
 * Lists the top 'perSubdomain' games of each subdomain (default 500 of all
 * eight), stores what the lists say, and fetches the polls of each game not
 * fetched in the last 'max_age_days' (default 30). A failed or queued poll
 * reply keeps the game's old polls and counts as failed. When every list
 * page answered, games no longer on any list leave the index, so the search
 * never ranks by a stale rank. 'pause_ms' spaces out the requests so a full
 * refresh does not hammer BGG.
 */
function bgg_poll_index_refresh($conn, array $options = [], $get_json = null) {
  $per_subdomain = max(1, (int) ($options['perSubdomain'] ?? 500));
  $subdomains = $options['subdomains'] ?? bgg_poll_subdomains();
  $pause_ms = (int) ($options['pause_ms'] ?? 250);
  $max_age_days = (int) ($options['max_age_days'] ?? 30);
  $requests = 0;
  $fetch = function ($url) use ($get_json, $pause_ms, &$requests) {
    if ($requests++ > 0 && $pause_ms > 0) {
      usleep($pause_ms * 1000);
    }
    return bgg_fetch($url, $get_json);
  };

  $result = ['ok' => true, 'listed' => 0, 'fetched' => 0, 'skipped' => 0, 'failed' => 0, 'lists_failed' => 0];
  $listed = [];
  $in = [];
  foreach ($subdomains as $family_id => $label) {
    $seen = 0;
    for ($page = 1; $seen < $per_subdomain; $page++) {
      try {
        $games = bgg_poll_listing_from_json($fetch(bgg_poll_listing_url($family_id, $page)));
      } catch (Throwable $e) {
        $games = false;
      }
      if ($games === false) {
        $result['lists_failed']++;
        break;
      }
      foreach (array_slice($games, 0, $per_subdomain - $seen) as $game) {
        $listed[$game['thing_id']] = $listed[$game['thing_id']] ?? $game;
        $in[$game['thing_id']][$label] = true;
      }
      $seen += count($games);
      if (count($games) < 50) {
        break;
      }
    }
  }

  $result['listed'] = count($listed);
  $fresh = bgg_poll_fresh_ids($conn, $max_age_days);
  foreach ($listed as $thing_id => $game) {
    bgg_poll_store_listing($conn, $game, implode(', ', array_keys($in[$thing_id])));
    if (isset($fresh[$thing_id])) {
      $result['skipped']++;
      continue;
    }
    try {
      $polls = bgg_poll_results_from_dynamic_json($fetch(bgg_dynamic_info_url($thing_id)));
    } catch (Throwable $e) {
      $polls = false;
    }
    if ($polls === false) {
      $result['failed']++;
      continue;
    }
    bgg_poll_store_results($conn, $thing_id, $polls);
    $result['fetched']++;
  }
  if ($result['lists_failed'] === 0) {
    bgg_poll_prune_unlisted($conn, array_keys($listed));
  }
  return $result;
}

// Drops every indexed game not in $thing_ids; its Best rows cascade.
function bgg_poll_prune_unlisted($conn, array $thing_ids) {
  if ($thing_ids === []) {
    mysqli_query($conn, 'DELETE FROM bgg_poll_games');
    return;
  }
  $stmt = mysqli_prepare(
    $conn,
    'DELETE FROM bgg_poll_games WHERE thing_id NOT IN (' . implode(',', array_fill(0, count($thing_ids), '?')) . ')'
  );
  mysqli_stmt_bind_param($stmt, str_repeat('i', count($thing_ids)), ...$thing_ids);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

// How much of BGG the index covers: ['games', 'polled', 'last_fetched'].
function bgg_poll_index_summary($conn) {
  $row = mysqli_fetch_assoc(mysqli_query(
    $conn,
    'SELECT COUNT(*) AS games, COUNT(polls_fetched_at) AS polled, MAX(polls_fetched_at) AS last_fetched FROM bgg_poll_games'
  ));
  return [
    'games' => (int) $row['games'],
    'polled' => (int) $row['polled'],
    'last_fetched' => $row['last_fetched'],
  ];
}

// [thing_id => item id] for the items the owner keeps that link to a BGG
// thing, so the search can say which games they already have.
function bgg_poll_kept_things(BggRatings $bgg_ratings) {
  $kept = [];
  foreach ($bgg_ratings->thingsByItem(true) as $artifact_id => $thing_id) {
    $kept[$thing_id] = $kept[$thing_id] ?? $artifact_id;
  }
  return $kept;
}

