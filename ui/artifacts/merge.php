<?php
// Edit Item's "Merge another item into this one": the chosen item's history
// moves to this item and the chosen item is deleted. initialize.php checks the
// CSRF token on every POST.
require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/item_merge.php');
require_login();

$survivor_id = filter_input(INPUT_POST, 'artifact_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_post_request() || !$survivor_id) {
  redirect_to(url_for('/artifacts/index.php'));
}

if (($_POST['merge_confirm'] ?? '') !== 'yes') {
  $_SESSION['message'] = 'Tick the box to confirm the merge.';
} else {
  $loser_title = find_item_for_merge($db, (int) ($_POST['merge_loser_id'] ?? 0))['Title'] ?? '';
  $result = merge_items($db, $survivor_id, (int) ($_POST['merge_loser_id'] ?? 0), (int) $_SESSION['user_id']);
  $_SESSION['message'] = $result === true
    ? 'Merged ' . $loser_title . ' into this item.'
    : implode(' ', $result);
}
// Edit Item answers 404 for an item the user does not own, so returning
// there is safe whatever the merge said.
redirect_to(url_for('/artifacts/edit.php?id=' . $survivor_id));
