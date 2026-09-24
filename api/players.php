<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  require_once('../private/players_list.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('players', ['method' => $_SERVER['REQUEST_METHOD']]);

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
  $response->authentication_response = $authentication_response;

  $method = $_SERVER['REQUEST_METHOD'];

  switch ($method) {

    case 'GET':
      // Read-only household players, scoped to the authenticated user
      if (!isset($authentication_response->user_id)) {
        http_response_code(400);
        $response->message = 'players.php requires a user-scoped key.';
        echo json_encode($response);
        exit;
      }

      $response->players = list_players_for_user($database, (int) $authentication_response->user_id);
      echo json_encode($response);
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET';
      echo json_encode($response);
      break;
  }

?>
