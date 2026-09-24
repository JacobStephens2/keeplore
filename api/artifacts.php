<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('artifacts', ['method' => $_SERVER['REQUEST_METHOD']]);

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
    echo json_encode($authentication_response);
    exit;
  }
  $response->authentication_response = $authentication_response;

  $requestBody = json_decode(
    file_get_contents('php://input')
  );

  if ($requestBody === null) {
    $requestBody = new stdClass;
  }

  // Agent keys are scoped to their own user: ignore any requested userid so
  // one agent cannot read another user's collection.
  $is_agent_key = isset($authentication_response->auth_type) && $authentication_response->auth_type === 'agent_key';
  if ($is_agent_key) {
    $requestBody->userid = $authentication_response->user_id;
  }

  $collection_user_id = 0;
  if (isset($requestBody->userid) && $requestBody->userid != '') {
    $collection_user_id = (int) $requestBody->userid;
  } elseif (isset($authentication_response->user_id)) {
    $collection_user_id = (int) $authentication_response->user_id;
  }

  $request = parse_collection_list_request($requestBody);
  if ($request['errors'] !== []) {
    http_response_code(400);
    $response->message = 'Invalid list request.';
    $response->errors = $request['errors'];
    echo json_encode($response);
    exit;
  }
  if ($collection_user_id === 0 && collection_list_has_filters($request)) {
    http_response_code(400);
    $response->message = 'Filters and extra fields need a collection: send userid.';
    echo json_encode($response);
    exit;
  }
  $response->per_page = $request['per_page'];

  if ($collection_user_id > 0) {
    $result = list_collection_items($database, $collection_user_id, $request);
    $response->artifacts = $result['items'];
    $response->has_more = $result['has_more'];
    if ($request['use_cursor']) {
      $response->next_cursor = $result['next_cursor'];
    } else {
      $response->page = $request['page'];
    }
  } elseif ($request['use_cursor']) {
    // Master API key without a userid: legacy all-users id/Title listing.
    $result = Artifact::list_artifacts_paginated($request['per_page'], $request['cursor']);
    $response->artifacts = $result['data'];
    $response->next_cursor = $result['next_cursor'];
    $response->has_more = $result['has_more'];
  } else {
    $response->artifacts = Artifact::list_artifacts($request['page'], $request['per_page']);
    $response->page = $request['page'];
  }

  echo json_encode($response);

?>
