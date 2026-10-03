<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('artifact', ['method' => $_SERVER['REQUEST_METHOD']]);

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
      // Get a single artifact by ID, scoped to authenticated user
      if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        $response->message = 'Missing or invalid required parameter: id';
        echo json_encode($response);
        exit;
      }

      $id = (int) $_GET['id'];
      $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

      if ($user_id) {
        $artifact = Artifact::find_by_id_and_user_id($id, $user_id);
      } else {
        $artifact = Artifact::find_by_id($id);
      }

      if (!$artifact) {
        http_response_code(404);
        $response->message = 'Item not found.';
        echo json_encode($response);
        exit;
      }

      if ($user_id) {
        $artifact = with_item_tags($database, [$artifact], $user_id)[0];
      }
      $response->artifact = $artifact;
      echo json_encode($response);
      break;

    case 'POST':
    case 'PUT':
    case 'DELETE':
      [$status, $fields] = $method === 'DELETE'
        ? delete_item_over_api($database, $authentication_response, $_GET)
        : write_item_over_api(
          $database, $authentication_response, $method, json_decode(file_get_contents('php://input'))
        );
      http_response_code($status);
      if (isset($fields['artifact'])) {
        $logger->logDataChange(ITEM_API_LOG_ACTIONS[$method], 'artifact', $fields['artifact']['id'], ['title' => $fields['artifact']['Title']]);
      }
      foreach ($fields as $field => $value) {
        $response->$field = $value;
      }
      echo json_encode($response);
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET, POST, PUT, DELETE';
      echo json_encode($response);
      break;
  }

?>
