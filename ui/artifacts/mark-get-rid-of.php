<?php
  require_once('../../private/initialize.php');
  require_login();

  $artifact_id = $_REQUEST['artifact_id'] ?? null;
  $value = isset($_REQUEST['value']) ? (int) $_REQUEST['value'] : 1;
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

  $result = set_artifact_to_get_rid_of($artifact_id, $value);

  if ($result) {
    $message = $value === 1
      ? $artifact_name . ' marked to get rid of.'
      : $artifact_name . ' restored to collection.';
  } else {
    $message = 'Failed to update item.';
  }

  if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => (bool) $result,
      'value' => $value,
      'artifact_id' => (int) $artifact_id,
      'artifact_name' => $artifact_name,
      'message' => $message,
    ]);
    exit;
  }

  $_SESSION['message'] = h($message);

  if ($return_to === 'to-get-rid-of') {
    redirect_to(url_for('/artifacts/to-get-rid-of.php'));
  } elseif ($return_to === 'dashboard') {
    redirect_to(url_for('/index.php') . '#priority-queue');
  } else {
    redirect_to(url_for('/artifacts/useby.php'));
  }
?>
