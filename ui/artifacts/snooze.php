<?php
  require_once('../../private/initialize.php');
  require_login();

  $artifact_id = $_REQUEST['artifact_id'] ?? null;
  $return_to = $_REQUEST['return_to'] ?? 'dashboard';
  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

  if ($artifact_id === null) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(400);
      echo json_encode(['ok' => false, 'message' => 'No item specified.']);
      exit;
    }
    $_SESSION['message'] = 'No item specified.';
    redirect_to(url_for('/index.php') . '#priority-queue');
  }

  // Snooze length: the user's default, optionally overridden by the request.
  $user_id = (int) $_SESSION['user_id'];
  $default_snooze_days = (int) (singleValueQuery(
    "SELECT default_snooze_days FROM users WHERE id = '" . $user_id . "'"
  ) ?? 7);
  if ($default_snooze_days < 1) {
    $default_snooze_days = 7;
  }
  $days = isset($_REQUEST['days']) ? (int) $_REQUEST['days'] : $default_snooze_days;
  if ($days < 1) {
    $days = $default_snooze_days;
  }

  $artifact_id = (int) $artifact_id;
  $artifact_record = find_owned_item_or_exit($artifact_id, $is_ajax);
  $artifact_name = $artifact_record['Title'];

  $snooze_until = (new Items($db, $user_id))->snooze($artifact_id, $days);

  $message = $artifact_name . ' snoozed until ' . $snooze_until . '.';

  if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => true,
      'artifact_id' => (int) $artifact_id,
      'artifact_name' => $artifact_name,
      'snoozed_until' => $snooze_until,
      'message' => $message,
    ]);
    exit;
  }

  $_SESSION['message'] = h($message);

  if ($return_to === 'useby') {
    redirect_to(url_for('/artifacts/useby.php'));
  } else {
    redirect_to(url_for('/index.php') . '#priority-queue');
  }
?>
