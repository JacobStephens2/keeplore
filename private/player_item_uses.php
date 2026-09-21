<?php

/**
 * A player's recorded uses on Edit User: load the rows, rank items by
 * how often the player participated, and share Item/Type table cells
 * between the ranking and the chronological list.
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

/**
 * Item and Type table cells for a player's use or ranking row.
 *
 * Both Edit User tables share this pair: a link to Edit Item and the
 * item type. Callers supply the first-column cell themselves.
 */
function player_use_item_cells(array $row) {
  $id = h(u($row['artifactID'] ?? ''));
  $title = h($row['Title'] ?? '');
  $type = h($row['type'] ?? '');
  $href = url_for('/artifacts/edit.php?id=' . $id);
  return '<td><a href="' . $href . '">' . $title . '</a></td>'
    . '<td>' . $type . '</td>';
}

/**
 * Load one player's recorded uses for the acting account, newest first.
 *
 * Each row has use_id, use_date, artifactID, Title, and type, the shape
 * rank_items_by_player_uses() and the chronological table both consume.
 */
function find_player_uses($conn, $user_id, $player_id) {
  $user_id = (int) $user_id;
  $player_id = (int) $player_id;
  $stmt = mysqli_prepare(
    $conn,
    "SELECT
      uses.id AS use_id,
      DATE(uses.use_date) AS use_date,
      games.id AS artifactID,
      games.Title,
      games.type
      FROM uses_players
      JOIN uses ON uses.id = uses_players.use_id
      JOIN games ON games.id = uses.artifact_id
      WHERE uses_players.user_id = ?
        AND uses_players.player_id = ?
      ORDER BY uses.use_date DESC"
  );
  mysqli_stmt_bind_param($stmt, "ii", $user_id, $player_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $rows = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
  }
  mysqli_stmt_close($stmt);
  return $rows;
}
