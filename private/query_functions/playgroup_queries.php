<?php

function count_playgroup() {
  global $db;
  $sql = "SELECT count(*) AS count FROM playgroup";
  $result = mysqli_query($db, $sql);
  confirm_result_set($result);
  $subject = mysqli_fetch_assoc($result);
  mysqli_free_result($result);
  return $subject; // returns an assoc. array
}

function choose_artifacts_for_group($range, $typeArray, $kept = 0) {
  global $db;
  $playgroup_count = count_playgroup();

  $sql ="SELECT
  games.title,
  games.id,
  games.ss,
  games.MnP,
  games.MxP,
  games.MxT,
  games.Age,
  games.type,
  games.is_kept,
  playgroup.FullName,
  players.id AS PlayerID,
  players.FirstName,
  players.LastName,
  players.G,
  players.Priority,
  responses.id AS ResponseID,
  Max(responses.AversionDate) AS MaxOfAversionDate,
  Max(responses.PlayDate) AS MaxOfPlayDate,
  Max(responses.PassDate) AS MaxOfPassDate,
  Max(responses.RequestDate) AS MaxOfRequestDate
  FROM (
        players
        LEFT JOIN (
            games
            LEFT JOIN responses ON games.ID = responses.Title
        ) ON players.ID = responses.Player
    )
    INNER JOIN playgroup ON players.ID = playgroup.FullName
  GROUP BY
    games.Title,
    games.MnP,
    games.Age,
    games.MxP,
    games.id,
    games.type,
    games.user_id,
    players.FirstName,
    players.LastName,
    playgroup.FullName,
    games.FavCt,
    players.G,
    players.Priority
  HAVING ";
  $sql .= " games.user_id = ? ";
  $sql .= "AND ( MaxOfPlayDate IS NOT NULL OR MaxOfAversionDate IS NOT NULL ) ";
  $params = [];
  $types = "i";
  $params[] = $_SESSION['user_id'];
  if ($range == 'true') {
    $count = (int) $playgroup_count['count'];
    $sql .= "AND games.MnP <= ? ";
    $sql .= "AND games.MxP >= ? ";
    $types .= "ii";
    $params[] = $count;
    $params[] = $count;
  }
  if (isset($typeArray) && $typeArray != 1 && count($typeArray) > 0) {
    $placeholders = implode(',', array_fill(0, count($typeArray), '?'));
    $sql .= "AND games.type IN (" . $placeholders . ") ";
    foreach($typeArray as $type) {
      $types .= "s";
      $params[] = $type;
    }
  }

  if ($kept == 1) {
    $sql .= " AND is_kept = 1 ";
  }
  $sql .= "ORDER BY
    players.G,
    players.Priority DESC,
    Max(responses.AversionDate) ASC,
    Max(responses.PlayDate) DESC,
    Max(responses.PassDate) ASC,
    Max(responses.RequestDate) DESC
  ";

  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  return $result;
}

function update_playgroup_player($playgroupplayer) {
    global $db;

    $sql = "UPDATE playgroup SET FullName=? WHERE ID=? LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $playgroupplayer['FullName'], $playgroupplayer['ID']);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    // For UPDATE statements, $result is true/false
    if($result) {
      return true;
    } else {
      // UPDATE failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }

  function delete_playgroup_player($ID) {
    global $db;

    $sql = "DELETE FROM playgroup WHERE ID=?";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "s", $ID);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // For DELETE statements, $result is true/false
    if($result) {
      return true;
    } else {
      // DELETE failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }

  function find_playgroup_by_user_id() {
    global $db;

    $user_id = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($db, "SELECT playgroup.ID, playgroup.FullName, players.FirstName, players.LastName, players.id AS playerID FROM playgroup LEFT JOIN players ON playgroup.FullName = players.id WHERE playgroup.user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function find_playgroup_player_by_id($ID) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT playgroup.ID, playgroup.FullName, players.FirstName, players.LastName FROM playgroup LEFT JOIN players ON playgroup.FullName = players.id WHERE playgroup.ID = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $ID);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    $subject = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return $subject;
  }

  function insert_playgroup($response) {
    global $db;

    $playerCount = $response['playerCount'];
    $sql = "INSERT INTO playgroup (FullName, user_id) VALUES (?, ?)";
    $stmt = mysqli_prepare($db, $sql);
    $i = 1;
    while($playerCount >= $i) {
      $playerValue = $response['Player' . $i];
      mysqli_stmt_bind_param($stmt, "si", $playerValue, $_SESSION['user_id']);
      $result = mysqli_stmt_execute($stmt);
      $i++;
    }
    mysqli_stmt_close($stmt);
    // For INSERT statements, $result is true/false
    if($result) {
      return true;
    } else {
      // INSERT failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
  }

}

?>
