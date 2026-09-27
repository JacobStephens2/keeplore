<?php
// Edit Item's "Request <user> data": fetch one BGG user's rating and comment
// for one item now, then return to Edit Item. initialize.php checks the CSRF
// token on every POST.
require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/bgg_ratings.php');
require_login();

$artifact_id = filter_input(INPUT_POST, 'artifact_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_post_request() || !$artifact_id) {
  redirect_to(url_for('/artifacts/index.php'));
}

// Edit Item answers 404 for an item the user does not own, so returning
// there is safe whatever the import said.
$result = bgg_ratings_import_item($db, (int) $_SESSION['user_id'], $artifact_id, (string) ($_POST['bgg_username'] ?? ''));
$_SESSION['message'] = $result['ok'] ? $result['message'] : $result['error'];
redirect_to(url_for('/artifacts/edit.php?id=' . $artifact_id));
