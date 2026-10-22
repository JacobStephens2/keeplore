<?php

/**
 * BoardGameGeek polls: what one poll reply says the community voted.
 * Create Item and the Search BGG index both read a dynamicinfo body here.
 * This module does not fetch BoardGameGeek and does not store the index.
 */

// An open-ended Best such as "9+" counts as Best at every count up to this.
const BGG_POLLS_OPEN_BEST_UP_TO = 20;

/**
 * One dynamicinfo body into the Best player counts (each with whether it is
 * only the tail of an open-ended vote), the text of those votes, the
 * player-poll vote count, the Community age, the Sweet spot an Item records,
 * and the recommended player range. False when the body is not a poll answer,
 * as while BGG queues a request or the item has no polls array.
 *
 * An open-ended vote records only its stated minimum on the Sweet spot and
 * on the recommended range. Best player counts run through the open-ended cap.
 * A count is open-only only when every range that includes it is that tail,
 * past the stated minimum. The player-poll total is never below 0.
 */
function bgg_polls_from_dynamic_json($json) {
  $data = json_decode((string) $json, true);
  $polls = (is_array($data) && is_array($data['item'] ?? null)) ? ($data['item']['polls'] ?? null) : null;
  if (!is_array($polls)) {
    return false;
  }

  $userplayers = is_array($polls['userplayers'] ?? null) ? $polls['userplayers'] : [];
  $ranges = $userplayers['best'] ?? [];
  $ranges = is_array($ranges) ? array_values(array_filter($ranges, 'is_array')) : [];
  usort($ranges, fn ($a, $b) => ((int) ($a['min'] ?? 0)) <=> ((int) ($b['min'] ?? 0)));

  $named = [];
  $covered = [];
  $parts = [];
  foreach ($ranges as $range) {
    $min = (int) ($range['min'] ?? 0);
    if ($min <= 0) {
      continue;
    }
    // A missing or null max is an open end ("9+"). A closed max below the
    // min stays the minimum, one count, for both projections.
    $open = !isset($range['max']);
    $stated_max = $open ? $min : max($min, (int) $range['max']);
    $stored_max = $open ? max($min, BGG_POLLS_OPEN_BEST_UP_TO) : $stated_max;
    for ($n = $min; $n <= $stored_max; $n++) {
      $covered[$n] = true;
      if (!$open || $n === $min) {
        $named[$n] = true;
      }
    }
    $parts[] = $open ? ($min . '+') : ($min === $stated_max ? (string) $min : $min . '-' . $stated_max);
  }

  ksort($covered);
  $best = [];
  foreach (array_keys($covered) as $players) {
    $best[] = ['players' => $players, 'open_only' => !isset($named[$players])];
  }
  ksort($named);
  $sweet_spot = $named === [] ? null : implode(',', array_map(
    fn ($n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT),
    array_keys($named)
  ));

  return [
    'best' => $best,
    'best_text' => implode(', ', $parts),
    'player_votes' => max(0, (int) ($userplayers['totalvotes'] ?? 0)),
    'community_age' => bgg_polls_community_age($polls['playerage'] ?? null),
    'sweet_spot' => $sweet_spot,
    'recommended' => bgg_polls_recommended_range($userplayers),
  ];
}

// The leading positive integer of the age poll ("10+" is 10). "(no votes)"
// and a blank are none.
function bgg_polls_community_age($age) {
  if (!is_string($age) || preg_match('/^(\d+)/', trim($age), $match) !== 1 || (int) $match[1] <= 0) {
    return null;
  }
  return (int) $match[1];
}

// Low and high of the recommended ranges. An open end stays its stated
// minimum; it does not run out to the Best cap. None when there is no range.
function bgg_polls_recommended_range(array $userplayers) {
  $recommended = $userplayers['recommended'] ?? null;
  if (!is_array($recommended)) {
    return null;
  }
  $low = null;
  $high = null;
  foreach ($recommended as $range) {
    if (!is_array($range)) {
      continue;
    }
    $min = isset($range['min']) ? (int) $range['min'] : 0;
    $max = isset($range['max']) ? (int) $range['max'] : $min;
    if ($min <= 0) {
      continue;
    }
    $low = $low === null ? $min : min($low, $min);
    $high = $high === null ? max($min, $max) : max($high, $min, $max);
  }
  return $low === null ? null : [$low, $high];
}
