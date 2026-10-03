<?php

  function validate_response($use) {
    $errors = [];

    return $errors;
  }

  function insert_aversion($response, $playerCount) {
    global $db;

    $errors = validate_response($response);
    if(!empty($errors)) {
      return $errors;
    }

    if ($playerCount >= 1) {
      $sql = "INSERT INTO responses (Title, AversionDate, Player, user_id) VALUES (?, ?, ?, ?)";
      $stmt = mysqli_prepare($db, $sql);
      for ($i = 1; $i <= $playerCount && $i <= 9; $i++) {
        $playerKey = 'Player' . $i;
        mysqli_stmt_bind_param($stmt, 'ssss',
          $response['Title'],
          $response['AversionDate'],
          $response[$playerKey],
          $_SESSION['user_id']
        );
        $result = mysqli_stmt_execute($stmt);
      }
      mysqli_stmt_close($stmt);
    }

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

  function find_uses_by_user_id($type, $minimumDate) {
    global $db;

    $params = [];
    $param_types = '';

    $sql = "SELECT
      games.Title,
      types.objectType AS type,
      games.Candidate,
      games.ss AS SwS,
      games.id AS gameID,
      uses.id AS useID,
      uses.note,
      uses.use_date,
      games.type_id
      FROM uses
      LEFT JOIN games ON uses.artifact_id = games.id
      LEFT JOIN types ON games.type_id = types.id
      WHERE uses.user_id = ?
      AND uses.use_date IS NOT NULL
    ";

    $params[] = $_SESSION['user_id'];
    $param_types .= 's';

    if (gettype($type == 'array')) {
      if (count($type) > 0) {
        $placeholders = implode(',', array_fill(0, count($type), '?'));
        $sql .= "AND games.type_id IN (" . $placeholders . ") ";
        foreach($type as $typeIndividual) {
          $params[] = $typeIndividual;
          $param_types .= 's';
        }
      } else {
        $sql .= " AND games.type_id = '' ";
      }
    }

    if ($minimumDate != '') {
      $sql .= " AND uses.use_date >= ? ";
      $params[] = $minimumDate;
      $param_types .= 's';
    }

    $sql .= " ORDER BY uses.use_date DESC,
      uses.id DESC,
      games.Title DESC
      LIMIT 9999
    ";

    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function find_aversions_by_user_id() {
  global $db;

  $sql = "SELECT ";
  $sql .= "games.Title, ";
  $sql .= "responses.id, ";
  $sql .= "players.FirstName, ";
  $sql .= "players.LastName, ";
  $sql .= "responses.AversionDate ";
  $sql .= "FROM responses ";
  $sql .= "LEFT JOIN games ON responses.Title = games.id ";
  $sql .= "LEFT JOIN players ON responses.Player = players.id ";
  $sql .= "WHERE responses.user_id = ? ";
  $sql .= "AND responses.AversionDate > 0 ";
  $sql .= "ORDER BY responses.AversionDate DESC, ";
  $sql .= "games.Title DESC, ";
  $sql .= "players.LastName ASC, ";
  $sql .= "players.FirstName ASC ";
  $sql .= "LIMIT 9999";

  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  confirm_result_set($result);
  return $result;
}

function find_response_by_id($id) {
  global $db;

  $sql = "SELECT ";
  $sql .= "games.Title, ";
  $sql .= "games.id AS gameid, ";
  $sql .= "responses.PlayDate, ";
  $sql .= "responses.Player, ";
  $sql .= "responses.Note AS Note, ";
  $sql .= "players.FirstName, ";
  $sql .= "players.LastName, ";
  $sql .= "responses.Title AS responsetitle, ";
  $sql .= "responses.AversionDate, ";
  $sql .= "responses.id ";
  $sql .= "FROM responses ";
  $sql .= "LEFT JOIN players ON responses.Player = players.id ";
  $sql .= "LEFT JOIN games ON responses.Title = games.id ";
  $sql .= "WHERE responses.id=? ";
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "s", $id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  confirm_result_set($result);
  $subject = mysqli_fetch_assoc($result);
  mysqli_free_result($result);
  return $subject; // returns an assoc. array
}

function update_response($response) {
  global $db;

  $errors = validate_response($response);
  if(!empty($errors)) {
    return $errors;
  }

  $sql = "UPDATE responses SET Title=?, PlayDate=?, Note=?, Player=? WHERE id=? LIMIT 1";
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, 'sssss',
    $response['Title'],
    $response['PlayDate'],
    $response['Note'],
    $response['Player'],
    $response['id']
  );
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

function delete_response($id) {
  global $db;

  $sql = "DELETE FROM responses WHERE id=? AND user_id=? LIMIT 1";
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "si", $id, $_SESSION['user_id']);
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

?>
