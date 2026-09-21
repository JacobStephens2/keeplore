<?php

/**
 * Rank items by how often a player participated in their recorded uses.
 *
 * Pure: no database access. Callers pass the player's already-scoped use
 * rows (artifactID, Title, type). Returns one row per item, most uses
 * first, with equal counts broken alphabetically by title.
 */

function rank_items_by_player_uses(array $uses) {
  $by_item = [];
  foreach ($uses as $use) {
    $artifact_id = (int) ($use['artifactID'] ?? 0);
    if ($artifact_id === 0) {
      continue;
    }
    if (!isset($by_item[$artifact_id])) {
      $by_item[$artifact_id] = [
        'artifactID' => $artifact_id,
        'Title' => $use['Title'] ?? '',
        'type' => $use['type'] ?? '',
        'use_count' => 0,
      ];
    }
    $by_item[$artifact_id]['use_count']++;
  }
  $ranked = array_values($by_item);
  usort($ranked, function ($a, $b) {
    $by_count = $b['use_count'] <=> $a['use_count'];
    if ($by_count !== 0) {
      return $by_count;
    }
    return strcasecmp($a['Title'], $b['Title']);
  });
  return $ranked;
}
