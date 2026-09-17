<?php

  // Kept toggle for remote agents (spec #10, ticket #18, ADR 0002).
  // POST { "id": 123, "is_kept": 1 } — the legacy "KeptCol" alias is also
  // accepted and both names are returned during the overlap release.

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

  $kept = resolve_agent_kept_input($requestBody);
  if ($kept === null) {
    http_response_code(400);
    $response->message = 'Missing required field: is_kept (0 or 1)';
    echo json_encode($response);
    exit;
  }

  $id = (int) $requestBody->id;
  $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

  // Fetch scoped to the authenticated user so agents can only flip their
  // own user's items. Legacy API keys without a user cannot use this endpoint.
  if ($user_id) {
    $stmt = $database->prepare("SELECT * FROM games WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $id, $user_id);
  } else {
    http_response_code(403);
    $response->message = 'This endpoint requires a user-scoped credential.';
    echo json_encode($response);
    exit;
  }
  $stmt->execute();
  $record = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$record) {
    http_response_code(404);
    $response->message = 'Item not found.';
    echo json_encode($response);
    exit;
  }

  // Single kept seam, dual-writing the new and legacy columns during the
  // overlap release.
  $stmt = $database->prepare("UPDATE games SET is_kept = ?, KeptCol = ? WHERE id = ? AND user_id = ? LIMIT 1");
  $stmt->bind_param("iiii", $kept, $kept, $id, $user_id);
  $stmt->execute();
  $stmt->close();

  $updated = Artifact::find_by_id_and_user_id($id, $user_id);
  $fields = agent_kept_fields($updated ? get_object_vars($updated) : $record);

  $logger->logDataChange('update', 'artifact-kept', $id, ['is_kept' => $kept]);

  $response->ok = true;
  $response->artifact_id = $id;
  $response->artifact_name = $updated ? $updated->Title : ($record['Title'] ?? 'Item');
  $response->message = $kept === 1
    ? $response->artifact_name . ' is now kept.'
    : $response->artifact_name . ' is no longer kept.';
  foreach ($fields as $field => $value) {
    $response->$field = $value;
  }
  echo json_encode($response);

?>
