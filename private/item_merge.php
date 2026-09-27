<?php

/**
 * Merging one item into another, for duplicate records such as two
 * "Dominion Expansions". The survivor keeps its own fields; everything that
 * points at the loser (uses, legacy responses, sweet spots, tags, BGG
 * ratings, proposals and "chosen instead" links) moves to the survivor, and
 * the loser is deleted. Where the survivor already has a tag or a BGG user's
 * rating, the survivor's wins.
 */

// Error strings; empty when the survivor may absorb the loser. Pure.
function validate_item_merge($survivor, $loser, $user_id) {
  if (!$survivor || !$loser) {
    return ['Both items must exist.'];
  }
  if ((int) $survivor['id'] === (int) $loser['id']) {
    return ['Cannot merge an item into itself.'];
  }
  if ((int) $survivor['user_id'] !== (int) $user_id || (int) $loser['user_id'] !== (int) $user_id) {
    return ['Both items must belong to your account.'];
  }
  return [];
}

/**
 * Edit Item's merge choices: every other item, those with the survivor's name
 * (ignoring case and spacing) first, then by name. Each carries 'same_name'.
 */
function item_merge_candidates(array $items, array $survivor) {
  $key = function ($title) {
    return strtolower(preg_replace('/\s+/', ' ', trim((string) $title)));
  };
  $survivor_key = $key($survivor['Title'] ?? '');
  $candidates = [];
  foreach ($items as $item) {
    if ((int) $item['id'] === (int) $survivor['id']) {
      continue;
    }
    $item['same_name'] = $key($item['Title'] ?? '') === $survivor_key;
    $candidates[] = $item;
  }
  usort($candidates, function ($a, $b) use ($key) {
    return [!$a['same_name'], $key($a['Title']), (int) $a['id']]
      <=> [!$b['same_name'], $key($b['Title']), (int) $b['id']];
  });
  return $candidates;
}

function find_item_for_merge($conn, $id) {
  $id = (int) $id;
  $stmt = mysqli_prepare($conn, 'SELECT id, user_id, Title FROM games WHERE id = ?');
  mysqli_stmt_bind_param($stmt, 'i', $id);
  mysqli_stmt_execute($stmt);
  $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  return $item ?: null;
}

// The owner's items as item_merge_candidates() expects them.
function find_items_for_merge($conn, $user_id) {
  $user_id = (int) $user_id;
  $stmt = mysqli_prepare($conn, 'SELECT id, user_id, Title FROM games WHERE user_id = ?');
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
  return $items;
}

/**
 * Merges $loser_id into $survivor_id in one transaction. Returns true, or
 * error strings when the guardrails refuse or the database fails, in which
 * case nothing changes.
 */
function merge_items($conn, $survivor_id, $loser_id, $user_id) {
  $survivor_id = (int) $survivor_id;
  $loser_id = (int) $loser_id;
  $errors = validate_item_merge(find_item_for_merge($conn, $survivor_id), find_item_for_merge($conn, $loser_id), $user_id);
  if ($errors !== []) {
    return $errors;
  }

  // The loser is the owner's, so every row pointing at it is about it,
  // including legacy rows written before user_id was recorded.
  $statements = [
    'UPDATE uses SET artifact_id = ? WHERE artifact_id = ?',
    'UPDATE responses SET Title = ? WHERE Title = ?',
    'UPDATE sweetspots SET Title = ? WHERE Title = ?',
    'UPDATE proposal_outcomes SET item_id = ? WHERE item_id = ?',
    'UPDATE proposal_outcomes SET chosen_item_id = ? WHERE chosen_item_id = ?',
    // IGNORE skips a tag or rating the survivor already has; the delete
    // below then drops the loser's copy.
    'UPDATE IGNORE item_tags SET artifact_id = ? WHERE artifact_id = ?',
    'UPDATE IGNORE item_bgg_ratings SET artifact_id = ? WHERE artifact_id = ?',
  ];
  try {
    mysqli_begin_transaction($conn);
    foreach ($statements as $sql) {
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, 'ii', $survivor_id, $loser_id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
    }
    // Where the loser was chosen instead of the survivor, that choice is now
    // the item itself, which says nothing, so the link goes.
    $stmt = mysqli_prepare($conn, 'UPDATE proposal_outcomes SET chosen_item_id = NULL WHERE item_id = ? AND chosen_item_id = item_id');
    mysqli_stmt_bind_param($stmt, 'i', $survivor_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    foreach (['item_tags', 'item_bgg_ratings'] as $table) {
      $stmt = mysqli_prepare($conn, "DELETE FROM {$table} WHERE artifact_id = ?");
      mysqli_stmt_bind_param($stmt, 'i', $loser_id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
    }
    $user_id = (int) $user_id;
    $stmt = mysqli_prepare($conn, 'DELETE FROM games WHERE id = ? AND user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $loser_id, $user_id);
    mysqli_stmt_execute($stmt);
    $deleted = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    // Deleted since the check above: keep everything as it was.
    if ($deleted !== 1) {
      throw new RuntimeException('item ' . $loser_id . ' vanished mid-merge');
    }
    mysqli_commit($conn);
  } catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('merge_items(' . $survivor_id . ', ' . $loser_id . '): ' . $e->getMessage());
    return ['The merge could not be completed.'];
  }
  return true;
}
