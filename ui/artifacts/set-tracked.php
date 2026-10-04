<?php
  require_once('../../private/initialize.php');
  require_login();

  $artifact_id = $_REQUEST['artifact_id'] ?? null;
  $value = isset($_REQUEST['value']) ? (int) $_REQUEST['value'] : 0;
  $return_to = $_REQUEST['return_to'] ?? 'useby';
  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

  if ($artifact_id === null) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(400);
      echo json_encode(['ok' => false, 'message' => 'No item specified.']);
      exit;
    }
    $_SESSION['message'] = 'No item specified.';
    redirect_to(url_for('/artifacts/useby.php'));
  }

  $artifact_id = (int) $artifact_id;
  $artifact_record = find_owned_item_or_exit($artifact_id, $is_ajax);
  $artifact_name = $artifact_record['Title'];

  (new Items($db, (int) $_SESSION['user_id']))->setKept($artifact_id, $value === 1);

  $message = $value === 1
    ? $artifact_name . ' is now kept.'
    : $artifact_name . ' is no longer kept.';

  if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => true,
      'value' => $value,
      'is_kept' => $value === 1 ? 1 : 0,
      'artifact_id' => (int) $artifact_id,
      'artifact_name' => $artifact_name,
      'message' => $message,
    ]);
    exit;
  }

  $_SESSION['message'] = h($message);

  if ($return_to === 'dashboard') {
    redirect_to(url_for('/index.php') . '#priority-queue');
  } elseif ($return_to === 'index') {
    redirect_to(url_for('/artifacts/index.php'));
  } elseif ($return_to === 'to-get-rid-of') {
    redirect_to(url_for('/artifacts/to-get-rid-of.php'));
  } elseif ($return_to === 'new') {
    redirect_to(url_for('/artifacts/new'));
  } else {
    redirect_to(url_for('/artifacts/useby.php'));
  }
?>
