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

  $artifact_record = find_artifact_by_id($artifact_id);
  if (!$artifact_record || (int) $artifact_record['user_id'] !== (int) $_SESSION['user_id']) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(404);
      echo json_encode(['ok' => false, 'message' => 'Item not found.']);
      exit;
    }
    $_SESSION['message'] = 'Item not found.';
    redirect_to(url_for('/artifacts/index.php'));
  }
  $artifact_name = $artifact_record['Title'] ?? ($_REQUEST['artifact_name'] ?? 'Item');

  // Single kept seam: flips kept through one setter (dual-writes the new
  // and legacy columns during the overlap release).
  $result = set_artifact_kept($artifact_id, $value);

  if ($result) {
    $message = $value === 1
      ? $artifact_name . ' is now kept.'
      : $artifact_name . ' is no longer kept.';
  } else {
    $message = 'Failed to update item.';
  }

  if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => (bool) $result,
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
  } else {
    redirect_to(url_for('/artifacts/useby.php'));
  }
?>
