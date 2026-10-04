<?php
// Edit Item's "Merge another item into this one": the chosen item's history
// moves to this item and the chosen item is deleted. initialize.php checks the
// CSRF token on every POST.
require_once('../../private/initialize.php');
require_login();

$survivor_id = filter_input(INPUT_POST, 'artifact_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_post_request() || !$survivor_id) {
  redirect_to(url_for('/artifacts/index.php'));
}

if (($_POST['merge_confirm'] ?? '') !== 'yes') {
  $_SESSION['message'] = 'Tick the box to confirm the merge.';
} else {
  $loser_id = (int) ($_POST['merge_loser_id'] ?? 0);
  try {
    (new Items($db, (int) $_SESSION['user_id']))->merge($survivor_id, $loser_id);
    // Duplicates share a name, so the message names the deleted record's id.
    $_SESSION['message'] = 'Merged item #' . $loser_id . ' into this item and deleted it.';
  } catch (OutOfBoundsException $not_found) {
    $_SESSION['message'] = 'Both items must belong to your account.';
  } catch (InvalidArgumentException $self_merge) {
    $_SESSION['message'] = 'Cannot merge an item into itself.';
  } catch (Throwable $error) {
    error_log('Items::merge(' . $survivor_id . ', ' . $loser_id . '): ' . $error->getMessage());
    $_SESSION['message'] = 'The merge could not be completed.';
  }
}
// Edit Item answers 404 for an item the user does not own, so returning
// there is safe whatever the merge said.
redirect_to(url_for('/artifacts/edit.php?id=' . $survivor_id));
