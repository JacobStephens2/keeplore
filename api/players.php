<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  require_once('../private/people_api.php');
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
      [$status, $fields] = list_people_over_api($database, $authentication_response);
      http_response_code($status);
      foreach ($fields as $field => $value) {
        $response->$field = $value;
      }
      echo json_encode($response);
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET';
      echo json_encode($response);
      break;
  }

?>
