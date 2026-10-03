<?php

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
   * playgroup slots, events) from loser to survivor, then deletes the
   * loser. Where the survivor is already linked (plays, proposal outcomes,
   * events),
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

    // Events: same drop-conflicts-then-repoint pattern, scoped to the
    // acting user's events through the parent table.
    $stmt = mysqli_prepare($db,
      "DELETE ep_loser FROM event_players AS ep_loser
       JOIN event_players AS ep_survivor
         ON ep_survivor.event_id = ep_loser.event_id
         AND ep_survivor.player_id = ?
       JOIN events AS e
         ON e.id = ep_loser.event_id AND e.user_id = ?
       WHERE ep_loser.player_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $survivor_id, $user_id, $loser_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($db,
      "UPDATE event_players AS ep
       JOIN events AS e
         ON e.id = ep.event_id AND e.user_id = ?
       SET ep.player_id = ? WHERE ep.player_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $user_id, $survivor_id, $loser_id);
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

?>
