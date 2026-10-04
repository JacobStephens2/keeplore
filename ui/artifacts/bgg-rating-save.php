<?php
// Edit Item's rating editor: save the owner's own rating and comment for one
// imported BGG user on one item, then return to Edit Item. initialize.php
// checks the CSRF token on every POST.
require_once('../../private/initialize.php');
require_login();

$artifact_id = filter_input(INPUT_POST, 'artifact_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_post_request() || !$artifact_id) {
  redirect_to(url_for('/artifacts/index.php'));
}

// Edit Item answers 404 for an item the user does not own, so returning
// there is safe whatever the save said.
try {
  $_SESSION['message'] = (new BggRatings($db, (int) $_SESSION['user_id']))->save(
    $artifact_id,
    (string) ($_POST['bgg_username'] ?? ''),
    (string) ($_POST['rating'] ?? ''),
    (string) ($_POST['comment'] ?? '')
  );
} catch (InvalidArgumentException | OutOfBoundsException | BggUnreachable $e) {
  $_SESSION['message'] = $e->getMessage();
}
redirect_to(url_for('/artifacts/edit.php?id=' . $artifact_id));
