<?php

/**
 * Search BGG's result for one owner. The caller passes the search form's
 * filters (bgg_poll_search_filters). The result says whether a Best player
 * count or a community age was set, the reviewer's names in reviewer order,
 * the cap, whether more games matched, and the rows.
 *
 * Neither of those filters means no rows and no ratings read: a caller
 * cannot dump the index by omitting both. The index summary is a different
 * question and stays with bgg_poll_index_summary.
 *
 * Each row carries the sort value and the display value the column already
 * uses. The page prints the display value. The browser sorts the same row.
 * The row's id is its place in this result, starting at 0, so a tie keeps
 * the search order. thing_id is the BoardGameGeek game, not the sort id.
 */

require_once __DIR__ . '/bgg_poll_index.php';

function bgg_search_results($conn, $user_id, array $filters) {
  $cap = BGG_POLL_SEARCH_LIMIT;
  $searched = ($filters['best'] ?? null) !== null || ($filters['age'] ?? null) !== null;
  if (!$searched) {
    return [
      'searched' => false,
      'reviewers' => [],
      'cap' => $cap,
      'more' => false,
      'rows' => [],
    ];
  }

  $games = bgg_poll_search($conn, $filters, $cap + 1);
  $more = count($games) > $cap;
  $games = array_slice($games, 0, $cap);

  $ratings = new BggRatings($conn, (int) $user_id);
  $kept = bgg_poll_kept_things($ratings);
  $reviewers = $ratings->reviewers();
  $reviews = $reviewers !== [] ? $ratings->byThing() : [];

  $rows = [];
  foreach ($games as $index => $game) {
    $rows[] = bgg_search_result_row($game, $index, $kept, $reviewers, $reviews);
  }
  return [
    'searched' => true,
    'reviewers' => $reviewers,
    'cap' => $cap,
    'more' => $more,
    'rows' => $rows,
  ];
}

// The leading whole number of Best text ("6-7" is 6, "6, 8" is 6, "9+" is
// 9). Empty text is none, not 0, so an empty Best sorts last.
function bgg_search_best_sort($text) {
  if (preg_match('/^(\d+)/', (string) $text, $match) !== 1) {
    return null;
  }
  return (int) $match[1];
}

function bgg_search_result_row(array $game, $index, array $kept, array $reviewers, array $reviews) {
  $thing_id = $game['thing_id'];
  $kept_id = $kept[$thing_id] ?? null;
  $age = $game['community_age'];
  $rank = $game['bgg_rank'];
  $average = $game['average'];
  $row = [
    'id' => $index,
    'thing_id' => $thing_id,
    'name' => $game['name'],
    'year' => $game['year_published'],
    'subdomains' => $game['subdomains'],
    'url' => $game['url'],
    'best' => bgg_search_best_sort($game['best_players']),
    'best_text' => $game['best_players'],
    'votes' => $game['player_votes'],
    'votes_text' => number_format($game['player_votes']),
    'age' => $age,
    'age_text' => $age === null ? '' : $age . '+',
    'rank' => $rank,
    'rank_text' => $rank === null ? '' : number_format($rank),
    'average' => $average,
    'average_text' => bgg_overall_rating_text($average) ?? '',
    'kept' => $kept_id !== null,
    'kept_item_id' => $kept_id,
    'kept_text' => $kept_id !== null ? 'Kept' : '',
    'reviews' => [],
  ];
  $by_reviewer = $reviews[$thing_id] ?? [];
  foreach ($reviewers as $reviewer) {
    $review = $by_reviewer[$reviewer] ?? null;
    if ($review === null) {
      $row['reviews'][$reviewer] = null;
      $row['bgg_rating:' . $reviewer] = null;
      continue;
    }
    $score = $review['rating'];
    $row['reviews'][$reviewer] = [
      'text' => $score === null ? '' : bgg_score_text($score),
      'item_id' => (int) $review['artifact_id'],
      'comment' => (string) ($review['comment'] ?? ''),
    ];
    $row['bgg_rating:' . $reviewer] = $score;
  }
  return $row;
}
