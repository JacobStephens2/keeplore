<?php

  function validate_response($use) {
    $errors = [];

    return $errors;
  }

  function insert_use($postArray) {

    /* Sample post request body

      $_POST: Array
      (
        [useDate] => 2023-01-12
        [artifact] => Array
          (
              [name] => Age of Empires IV
              [id] => 2807
          )

        [user] => Array
          (
            [0] => Array
                (
                    [name] => Jacob Stephens
                    [id] => 141
                )

            [1] => Array
                (
                    [name] => Luke Boerman
                    [id] => 91
                )

          )
      )
    */

    global $db;

    $queriesArray = [];

    // table uses
    $query = "INSERT INTO uses (
      artifact_id,
      use_date,
      user_id,
      notesTwo,
      note
      ) VALUES (?, ?, ?, ?, ?)
    ";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, 'sssss',
      $postArray['artifact']['id'],
      $postArray['useDate'],
      $_SESSION['user_id'],
      $postArray['NotesTwo'],
      $postArray['Note']
    );
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $use_id = mysqli_insert_id($db);

    // table uses_players
    $query = "INSERT INTO uses_players (
      use_id,
      player_id,
      user_id
      ) VALUES (?, ?, ?)
    ";
    $stmt = mysqli_prepare($db, $query);

    $i = 0;
    $seen_player_ids = [];
    foreach($postArray['user'] as $userArray) {
      $player_id = (int) ($userArray['id'] ?? '');
      if ($player_id === 0 || isset($seen_player_ids[$player_id])) {
        continue;
      }
      $seen_player_ids[$player_id] = true;
      mysqli_stmt_bind_param($stmt, 'sss',
        $use_id,
        $userArray['id'],
        $_SESSION['user_id']
      );
      $result = mysqli_stmt_execute($stmt);
      $i++;
    }
    mysqli_stmt_close($stmt);

    // For INSERT statements, $result is true/false
    if ($result) {
      return $result;
    } else {
      // INSERT failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
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

function find_users_by_use_id($use_id) {
  global $db;
  $query = "SELECT
    players.FirstName,
    players.LastName,
    players.id
    FROM uses_players
    LEFT JOIN players ON uses_players.player_id = players.id
    WHERE uses_players.user_id=?
    AND uses_players.use_id = ?
  ";
  $stmt = mysqli_prepare($db, $query);
  mysqli_stmt_bind_param($stmt, "is", $_SESSION['user_id'], $use_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  confirm_result_set($result);
  return $result; // returns an assoc. array
  mysqli_free_result($result);
}

function find_use_details_by_id($id) {
  global $db;

  $sql = "SELECT
    games.id AS game_id,
    games.Title AS artifact,
    uses.use_date,
    uses.note AS note,
    uses.notesTwo AS notesTwo,
    uses.id
    FROM uses
    LEFT JOIN games ON uses.artifact_id = games.id
    WHERE uses.id=?
  ";

  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "s", $id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  confirm_result_set($result);
  $subject = mysqli_fetch_assoc($result);
  mysqli_free_result($result);
  return $subject; // returns an assoc. array
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

function update_use($useArray) {
  global $db;

  $errors = validate_response($useArray);
  if(!empty($errors)) {
    return $errors;
  }

  $sql = "UPDATE uses SET
    artifact_id=?,
    use_date=?,
    notesTwo=?,
    note=?
    WHERE id=?
    AND user_id=?
    LIMIT 1
  ";

  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, 'ssssss',
    $useArray['artifact_id'],
    $useArray['use_date'],
    $useArray['notesTwo'],
    $useArray['note'],
    $useArray['use_id'],
    $_SESSION['user_id']
  );
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_affected_rows($stmt) !== -1;
  // For UPDATE statements, $result is true/false

  if($result) {
    // do nothing
  } else {
    // UPDATE failed
    echo mysqli_stmt_error($stmt);
    db_disconnect($db);
    exit;
  }

  $query = "DELETE FROM uses_players
    WHERE use_id = ?
    AND user_id=?
  ";
  $stmt = mysqli_prepare($db, $query);
  mysqli_stmt_bind_param($stmt, 'ss', $useArray['use_id'], $_SESSION['user_id']);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_affected_rows($stmt) !== -1;
  if($result) {
    // do nothing
  } else {
    // UPDATE failed
    echo mysqli_stmt_error($stmt);
    db_disconnect($db);
    exit;
  }

  $seen_player_ids = [];
  foreach ($useArray['user'] as $user) {
    if ($user['name'] == '') {
      continue;
    }
    $player_id = (int) ($user['id'] ?? '');
    if ($player_id === 0 || isset($seen_player_ids[$player_id])) {
      continue;
    }
    $seen_player_ids[$player_id] = true;
    $query = "INSERT INTO uses_players (
        use_id,
        player_id,
        user_id
      ) VALUES (
        ?,
        ?,
        ?
      )
    ";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, 'sss', $useArray['use_id'], $user['id'], $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_affected_rows($stmt) !== -1;
    if($result) {
      // do nothing
    } else {
      // UPDATE failed
      echo mysqli_stmt_error($stmt);
      db_disconnect($db);
      exit;
    }
  }

  return true;
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

function delete_use_by_id($use_id) {
  global $db;

  $sql = "DELETE FROM uses WHERE id=? AND user_id=? LIMIT 1";
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "si", $use_id, $_SESSION['user_id']);
  $result = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

  // For DELETE statements, $result is true/false
  if($result) {
    // do nothing
  } else {
    // DELETE failed
    echo mysqli_error($db);
    db_disconnect($db);
    exit;
  }

  $sql = "DELETE FROM uses_players WHERE use_id=? AND user_id=?";
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, "si", $use_id, $_SESSION['user_id']);
  $result = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

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
