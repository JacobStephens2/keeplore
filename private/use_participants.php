<?php

/**
 * Participants seam for plays reads (issue #9, brief 1).
 *
 * Plays (`uses`) and participants (`uses_players` + `players`) are stored
 * separately. Readers that need "who played?" attach the two sides with
 * attach_participants_to_uses(), which collapses duplicate junction rows
 * (same player linked several times to one play) to a single participant.
 */

/**
 * Attach deduplicated participant lists to play rows.
 *
 * Pure: no database access. Each entry of $uses gains a `participants`
 * key: a list of ['id', 'FirstName', 'LastName'] arrays, one per distinct
 * player linked to that play. Rows for unknown play ids are ignored and
 * plays without participants get an empty list. Inputs are not mutated.
 */
function attach_participants_to_uses(array $uses, array $participant_rows) {
  $by_use = [];
  foreach ($participant_rows as $row) {
    $use_id = (int) ($row['use_id'] ?? 0);
    $player_id = (int) ($row['player_id'] ?? 0);
    if ($use_id === 0 || $player_id === 0) {
      continue;
    }
    if (!isset($by_use[$use_id])) {
      $by_use[$use_id] = [];
    }
    if (!isset($by_use[$use_id][$player_id])) {
      $by_use[$use_id][$player_id] = [
        'id' => $player_id,
        'FirstName' => $row['FirstName'] ?? '',
        'LastName' => $row['LastName'] ?? '',
      ];
    }
  }

  $attached = [];
  foreach ($uses as $use) {
    $use_id = (int) ($use['id'] ?? 0);
    $use['participants'] = array_values($by_use[$use_id] ?? []);
    $attached[] = $use;
  }
  return $attached;
}

/**
 * Fetch participant rows for a set of play ids.
 *
 * Scoped to one user when $user_id is given; when null, scoping comes
 * from the play ids themselves (callers must only pass ids from an
 * already-scoped plays query). Returns flat rows with use_id,
 * player_id, FirstName, LastName for attach_participants_to_uses().
 * Returns [] when $use_ids is empty.
 */
function find_participants_for_uses($conn, array $use_ids, $user_id = null) {
  $use_ids = array_values(array_unique(array_map('intval', $use_ids)));
  $use_ids = array_filter($use_ids);
  if (empty($use_ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($use_ids), '?'));
  $types = str_repeat('i', count($use_ids));
  $params = $use_ids;
  $user_filter = '';
  if ($user_id !== null) {
    $user_filter = ' AND uses_players.user_id = ?';
    $types .= 'i';
    $params[] = (int) $user_id;
  }
  $stmt = mysqli_prepare(
    $conn,
    "SELECT uses_players.use_id, uses_players.player_id, players.FirstName, players.LastName
     FROM uses_players
     LEFT JOIN players ON uses_players.player_id = players.id
     WHERE uses_players.use_id IN ({$placeholders}){$user_filter}
     ORDER BY uses_players.use_id, players.FirstName, players.LastName"
  );
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $rows = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
  }
  mysqli_stmt_close($stmt);
  return $rows;
}

?>
