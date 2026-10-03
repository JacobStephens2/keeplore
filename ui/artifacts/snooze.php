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

  $artifact_record = (new Items($db, (int) $_SESSION['user_id']))->find((int) $artifact_id);
  if (!$artifact_record) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(404);
      echo json_encode(['ok' => false, 'message' => 'Item not found.']);
      exit;
    }
    $_SESSION['message'] = 'Item not found.';
    redirect_to(url_for('/artifacts/index.php'));
  }
  $artifact_name = $artifact_record['Title'];

  $snooze_until = snooze_artifact($artifact_id, $days);

  if ($snooze_until !== false) {
    $message = $artifact_name . ' snoozed until ' . $snooze_until . '.';
  } else {
    $message = 'Failed to snooze item.';
  }

  if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => $snooze_until !== false,
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
