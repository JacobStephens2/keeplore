<?php

  // Kept toggle for remote agents (spec #10, ticket #18, ADR 0002).
  // POST { "id": 123, "is_kept": 1 } — exactly one kept vocabulary.

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('artifact-kept', ['method' => $_SERVER['REQUEST_METHOD']]);

  $response = new stdClass;

  // Rate limit: 60 requests per minute per IP
  $rate_limiter = new RateLimiter($database);
  if (!$rate_limiter->checkAndRecord('api', 60, 60)) {
    http_response_code(429);
    $response->message = 'Rate limit exceeded. Please try again later.';
    echo json_encode($response);
    exit;
  }

  $authentication_response = authenticate();
  if ($authentication_response->authenticated != true) {
    http_response_code(401);
    echo json_encode($authentication_response);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response->message = 'Method not allowed. Supported methods: POST';
    echo json_encode($response);
    exit;
  }

  $requestBody = json_decode(file_get_contents('php://input'));

  if (!$requestBody || !isset($requestBody->id) || !is_numeric($requestBody->id)) {
    http_response_code(400);
    $response->message = 'Missing or invalid required field: id';
    echo json_encode($response);
    exit;
  }

  $body = (array) $requestBody;
  $raw_kept = $body['is_kept'] ?? null;
  // Strict 0/1: anything else (including truthy strings) is rejected rather
  // than silently coerced, so a malformed call can never flip kept by accident.
  $kept = in_array($raw_kept, [0, 1, '0', '1', true, false], true) ? (int) $raw_kept : null;
  if ($kept === null) {
    http_response_code(400);
    $response->message = 'Missing or invalid required field: is_kept (0 or 1)';
    echo json_encode($response);
    exit;
  }

  $id = (int) $requestBody->id;
  $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

  // The key owner's Items, so agents can only flip their own user's items.
  // Legacy API keys without a user cannot use this endpoint.
  if (!$user_id) {
    http_response_code(403);
    $response->message = 'This endpoint requires a user-scoped credential.';
    echo json_encode($response);
    exit;
  }
  $items = new Items($database, $user_id);

  if ($items->find($id) === null) {
    http_response_code(404);
    $response->message = 'Item not found.';
    echo json_encode($response);
    exit;
  }

  // Single kept seam: exactly one kept vocabulary.
  $items->setKept($id, $kept === 1);
  $row = $items->find($id);

  $logger->logDataChange('update', 'artifact-kept', $id, ['is_kept' => $kept]);

  $response->ok = true;
  $response->artifact_id = $id;
  $response->artifact_name = $row['Title'];
  $response->message = $kept === 1
    ? $response->artifact_name . ' is now kept.'
    : $response->artifact_name . ' is no longer kept.';
  $response->is_kept = artifact_is_kept($row) ? 1 : 0;
  $response->is_in_secondary_collection = artifact_is_in_secondary_collection($row) ? 1 : 0;
  echo json_encode($response);

?>
