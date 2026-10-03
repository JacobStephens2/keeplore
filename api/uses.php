<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  require_once('../private/use_api.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('uses', ['method' => $_SERVER['REQUEST_METHOD']]);

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
    case 'POST':
    case 'DELETE':
      [$status, $fields] = match ($method) {
        'GET' => list_uses_over_api($database, $authentication_response, $_GET),
        'POST' => record_use_over_api($database, $authentication_response, json_decode(file_get_contents('php://input'))),
        'DELETE' => delete_use_over_api($database, $authentication_response, $_GET),
      };
      http_response_code($status);
      if ($method === 'POST' && isset($fields['use'])) {
        $logger->logDataChange('create', 'use', $fields['use']['id'], [
          'artifact_id' => $fields['use']['artifact_id'],
          'use_date' => $fields['use']['use_date']
        ]);
      } elseif ($method === 'DELETE' && $status === 200) {
        $logger->logDataChange('delete', 'use', (int) $_GET['id']);
      }
      foreach ($fields as $field => $value) {
        $response->$field = $value;
      }
      echo json_encode($response);
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET, POST, DELETE';
      echo json_encode($response);
      break;
  }

?>
