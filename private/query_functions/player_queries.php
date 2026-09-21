<?php

  function list_players() {
    global $db;

    $user_id = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($db, "SELECT id, FirstName, LastName FROM players WHERE user_id = ? ORDER BY FirstName ASC, LastName ASC");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function find_player_by_id($id) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT * FROM players WHERE players.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    $subject = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return $subject;
  }

  function find_players_by_user_id() {
    global $db;

    $user_id = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($db, "SELECT * FROM players WHERE user_id = ? ORDER BY id DESC");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function insert_player($player) {
    global $db;

    $sql = "INSERT INTO players (FirstName, LastName, FullName, G, birth_year, user_id) VALUES (?, ?, ?, ?, ?, ?)";
    $fullName = $player['FirstName'] . ' ' . $player['LastName'];
    $stmt = mysqli_prepare($db, $sql);
    $birthYear = $player['birth_year'] !== '' ? (int) $player['birth_year'] : null;
    mysqli_stmt_bind_param($stmt, "ssssii", $player['FirstName'], $player['LastName'], $fullName, $player['G'], $birthYear, $_SESSION['user_id']);
    $result = mysqli_stmt_execute($stmt);
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
  function update_player($player) {
    global $db;

    $birthYear = $player['birth_year'] !== '' ? (int) $player['birth_year'] : null;
    if ($player['thisPlayerIsMe'] === 'yes') {
      $sql = "UPDATE players SET FirstName=?, LastName=?, G=?, represents_user_id=?, birth_year=? WHERE id=? LIMIT 1";
      $stmt = mysqli_prepare($db, $sql);
      mysqli_stmt_bind_param($stmt, "ssssii", $player['FirstName'], $player['LastName'], $player['G'], $player['user_id'], $birthYear, $player['id']);
    } else {
      $sql = "UPDATE players SET FirstName=?, LastName=?, G=?, represents_user_id = NULL, birth_year=? WHERE id=? LIMIT 1";
      $stmt = mysqli_prepare($db, $sql);
      mysqli_stmt_bind_param($stmt, "sssii", $player['FirstName'], $player['LastName'], $player['G'], $birthYear, $player['id']);
    }
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($player['thisPlayerIsMe'] === 'yes') {
      // Update user record with player_id
      $updateUserQuery = "UPDATE users SET player_id = ? WHERE id = ? LIMIT 1";
      $stmt2 = mysqli_prepare($db, $updateUserQuery);
      mysqli_stmt_bind_param($stmt2, "ss", $player['id'], $player['user_id']);
    } else {
      $updateUserQuery = "UPDATE users SET player_id = NULL WHERE id = ? LIMIT 1";
      $stmt2 = mysqli_prepare($db, $updateUserQuery);
      mysqli_stmt_bind_param($stmt2, "s", $player['user_id']);
    }
    $updateUserResult = mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);

    // For UPDATE statements, $result is true/false
    if($result) {
      if (isset($updateUserResult)) {
        if ($updateUserResult) {
          return true;
        } else {
          echo mysqli_error($db);
          db_disconnect($db);
          exit;
        }
      } else {
        return true;
      }
    } else {
      // UPDATE failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }
  /**
   * Merge guardrails (issue #9, brief 3). Pure: no database access.
   *
   * Returns a list of error strings; an empty list means the survivor
   * may absorb the loser. Rules: both records exist, distinct ids, both
   * belong to the acting user, and when either record represents the
   * owner ("me") the survivor must be that record.
   */
  function validate_player_merge($survivor, $loser, $user_id) {
    $errors = [];
    if (!$survivor || !$loser) {
      $errors[] = "Both players must exist.";
      return $errors;
    }
    if ((int) $survivor['id'] === (int) $loser['id']) {
      $errors[] = "Cannot merge a player into itself.";
    }
    if ((int) $survivor['user_id'] !== (int) $user_id
      || (int) $loser['user_id'] !== (int) $user_id) {
      $errors[] = "Both players must belong to your account.";
    }
    $survivor_is_me = !empty($survivor['represents_user_id']);
    $loser_is_me = !empty($loser['represents_user_id']);
    if (($survivor_is_me || $loser_is_me) && !$survivor_is_me) {
      $errors[] = "The surviving player must be the one marked as you.";
    }
    return $errors;
  }

  /**
   * Merge the losing player into the surviving player (issue #9, brief 3).
   *
   * Re-points every participation row (plays, proposal outcomes,
   * playgroup slots) from loser to survivor, then deletes the loser.
   * Where the survivor is already linked (plays, proposal outcomes),
   * the loser's redundant link is dropped so no play gains or loses
   * participants overall. Returns true on success, or an array of
   * error strings when the guardrails refuse.
   */
  function merge_players($survivor_id, $loser_id, $user_id) {
    global $db;

    $survivor_id = (int) $survivor_id;
    $loser_id = (int) $loser_id;
    $user_id = (int) $user_id;

    $errors = validate_player_merge(
      find_player_by_id($survivor_id),
      find_player_by_id($loser_id),
      $user_id
    );
    if (!empty($errors)) {
      return $errors;
    }

    // Safety net: never orphan the account's owner link.
    $stmt = mysqli_prepare($db, "SELECT id FROM users WHERE id = ? AND player_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $loser_id);
    mysqli_stmt_execute($stmt);
    $owner_check = mysqli_stmt_get_result($stmt);
    $loser_is_owner = mysqli_fetch_assoc($owner_check) !== null;
    mysqli_stmt_close($stmt);
    if ($loser_is_owner) {
      return ["The surviving player must be the one marked as you."];
    }

    // Plays: drop loser links the survivor already has, re-point the rest.
    $stmt = mysqli_prepare($db,
      "DELETE up_loser FROM uses_players AS up_loser
       JOIN uses_players AS up_survivor
         ON up_survivor.use_id = up_loser.use_id
         AND up_survivor.player_id = ?
       WHERE up_loser.player_id = ? AND up_loser.user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $survivor_id, $loser_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($db,
      "UPDATE uses_players SET player_id = ?
       WHERE player_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $survivor_id, $loser_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Proposal outcomes: same drop-conflicts-then-repoint pattern
    // (composite primary key on proposal_id + player_id), scoped to the
    // acting user's proposals through the parent table.
    $stmt = mysqli_prepare($db,
      "DELETE pop_loser FROM proposal_outcome_players AS pop_loser
       JOIN proposal_outcome_players AS pop_survivor
         ON pop_survivor.proposal_id = pop_loser.proposal_id
         AND pop_survivor.player_id = ?
       JOIN proposal_outcomes AS po
         ON po.id = pop_loser.proposal_id AND po.user_id = ?
       WHERE pop_loser.player_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $survivor_id, $user_id, $loser_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($db,
      "UPDATE proposal_outcome_players AS pop
       JOIN proposal_outcomes AS po
         ON po.id = pop.proposal_id AND po.user_id = ?
       SET pop.player_id = ? WHERE pop.player_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $user_id, $survivor_id, $loser_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Playgroup slots: plain re-point scoped to the acting user,
    // no uniqueness involved.
    $stmt = mysqli_prepare($db,
      "UPDATE playgroup SET FullName = ? WHERE FullName = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $survivor_id, $loser_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Finally remove the losing record (scoped to the acting user).
    $stmt = mysqli_prepare($db,
      "DELETE FROM players WHERE id = ? AND user_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ii", $loser_id, $user_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($result) {
      return true;
    }
    return ["The merge could not be completed."];
  }

  function delete_player($id) {
    global $db;

    $sql = "DELETE FROM players WHERE id=? LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "s", $id);
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
