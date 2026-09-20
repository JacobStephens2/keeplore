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

  $tag = isset($requestBody->tag) ? (string) $requestBody->tag : '';
  $collection_user_id = 0;
  if (isset($requestBody->userid) && $requestBody->userid != '') {
    $collection_user_id = (int) $requestBody->userid;
  } elseif (isset($authentication_response->user_id)) {
    $collection_user_id = (int) $authentication_response->user_id;
  }

  $per_page = isset($requestBody->per_page) ? (int) $requestBody->per_page : 50;

  // Determine pagination mode: cursor-based or offset-based
  if (isset($requestBody->cursor)) {
    // Cursor-based pagination
    $cursor = $requestBody->cursor !== null ? (int) $requestBody->cursor : null;

    if (isset($requestBody->query) && $requestBody->query != '') {
      // Query search does not support cursor-based pagination; fall back to offset
      $page = isset($requestBody->page) ? (int) $requestBody->page : 1;
      $artifacts = Artifact::list_artifacts_by_query(
        $requestBody->query,
        $requestBody->userid,
        $page,
        $per_page,
        $tag
      );
      $response->artifacts = $artifacts;
      $response->page = $page;
      $response->per_page = $per_page;
    } elseif (isset($requestBody->userid) && $requestBody->userid != '') {
      $result = Artifact::list_artifacts_by_user_paginated(
        $requestBody->userid,
        $per_page,
        $cursor,
        $tag
      );
      $response->artifacts = $result['data'];
      $response->next_cursor = $result['next_cursor'];
      $response->has_more = $result['has_more'];
      $response->per_page = $per_page;
    } else {
      $result = Artifact::list_artifacts_paginated($per_page, $cursor);
      $response->artifacts = $result['data'];
      $response->next_cursor = $result['next_cursor'];
      $response->has_more = $result['has_more'];
      $response->per_page = $per_page;
    }
  } else {
    // Offset-based pagination (existing behavior)
    $page = isset($requestBody->page) ? (int) $requestBody->page : 1;

    if (isset($requestBody->query) && $requestBody->query != '') {
      $artifacts = Artifact::list_artifacts_by_query(
        $requestBody->query,
        $requestBody->userid,
        $page,
        $per_page,
        $tag
      );
    } elseif ($is_agent_key || ($tag !== '' && $collection_user_id > 0)) {
      $artifacts = Artifact::list_artifacts_by_user(
        $collection_user_id > 0 ? $collection_user_id : $authentication_response->user_id,
        $page,
        $per_page,
        $tag
      );
    } else {
      $artifacts = Artifact::list_artifacts($page, $per_page);
    }
    if (!isset($response->artifacts)) {
      $response->artifacts = $artifacts;
      $response->page = $page;
      $response->per_page = $per_page;
    }
  }

  if ($collection_user_id > 0 && isset($response->artifacts) && is_array($response->artifacts)) {
    $response->artifacts = with_item_tags($database, $response->artifacts, $collection_user_id);
  }

  echo json_encode($response);

?>