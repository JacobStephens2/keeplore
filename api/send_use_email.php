<?php

  require_once('../private/initialize.php');
  header('Content-Type: application/json');

  $response = new stdClass;

  // Authenticate via JWT cookie and only allow sending the email to the
  // currently logged-in user.
  $authentication_response = authenticate();
  if (!isset($authentication_response->authenticated) || $authentication_response->authenticated !== true) {
    http_response_code(401);
    $response->message = 'You are not authenticated.';
    echo json_encode($response);
    exit;
  }

  // Triggering email sends is outside the agent scope (reads plus kept toggle).
  deny_agent_key_writes($authentication_response);

  $authenticated_user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;
  $requested_user_id = isset($_GET['userID']) ? (int) $_GET['userID'] : null;

  if (!$authenticated_user_id || !$requested_user_id || $authenticated_user_id !== $requested_user_id) {
    http_response_code(403);
    $response->message = 'You may only send this email to yourself.';
    echo json_encode($response);
    exit;
  }

  $response->userID = $authenticated_user_id;
  $response->count_to_notify_about = email_artifact_use_notice($authenticated_user_id);

  echo json_encode($response);

?>
