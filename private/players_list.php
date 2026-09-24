<?php

/**
 * Read-only players list behind GET /players.php (issue #58): the ids an
 * agent needs to filter plays by person with GET /uses.php?player_id=.
 */

function list_players_for_user($conn, $user_id) {
  $user_id = (int) $user_id;
  $stmt = mysqli_prepare(
    $conn,
    "SELECT id, FirstName, LastName, FullName, birth_year, represents_user_id
     FROM players
     WHERE user_id = ?
     ORDER BY FirstName ASC, LastName ASC, id ASC"
  );
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $players = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $name = trim((string) ($row['FullName'] ?? ''));
    if ($name === '') {
      $name = trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? ''));
    }
    $players[] = [
      'id' => (int) $row['id'],
      'name' => $name,
      'FirstName' => $row['FirstName'],
      'LastName' => $row['LastName'],
      'birth_year' => $row['birth_year'] === null ? null : (int) $row['birth_year'],
      'represents_user_id' => $row['represents_user_id'] === null ? null : (int) $row['represents_user_id'],
    ];
  }
  mysqli_stmt_close($stmt);
  return $players;
}

?>
