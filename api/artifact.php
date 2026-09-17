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

      $response->artifact = $artifact;
      echo json_encode($response);
      break;

    case 'POST':
      // Agent keys permit reads plus the kept toggle only.
      deny_agent_key_writes($authentication_response);
      // Create a new artifact
      $requestBody = json_decode(file_get_contents('php://input'));

      if (!$requestBody) {
        http_response_code(400);
        $response->message = 'Invalid or missing JSON request body.';
        echo json_encode($response);
        exit;
      }

      if (!isset($requestBody->Title) || trim($requestBody->Title) === '') {
        http_response_code(400);
        $response->message = 'Title is required.';
        echo json_encode($response);
        exit;
      }

      $artifact = new Artifact();
      $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

      // Map request body fields onto the artifact object
      $allowed_fields = [
        'Access', 'Acq', 'Age', 'age_max', 'Av', 'BGG_Rat', 'Candidate',
        'FavCt', 'FullTitle', 'is_digital', 'is_in_secondary_collection',
        'is_kept', 'is_physical', 'MnP',
        'MnT', 'MxP', 'MxT', 'OrigPlat', 'SS', 'System', 'Title',
        'to_get_rid_of', 'type', 'UsedRecUserCt', 'Wt', 'Yr'
      ];

      foreach ($allowed_fields as $field) {
        if (isset($requestBody->$field)) {
          $artifact->$field = $requestBody->$field;
        }
      }

      // Always set user_id from the authenticated user
      if ($user_id) {
        $artifact->user_id = $user_id;
      } elseif (isset($requestBody->user_id)) {
        $artifact->user_id = (int) $requestBody->user_id;
      }

      $result = $artifact->save();

      if ($result === true) {
        http_response_code(201);
        $logger->logDataChange('create', 'artifact', $artifact->id, ['title' => $artifact->Title]);
        $response->message = 'Item created successfully.';
        $response->artifact = $artifact;
        echo json_encode($response);
      } else {
        http_response_code(422);
        $response->message = 'Failed to create item.';
        $response->errors = $artifact->errors;
        echo json_encode($response);
      }
      break;

    case 'PUT':
      // Agent keys permit reads plus the kept toggle only.
      deny_agent_key_writes($authentication_response);
      // Update an existing artifact
      $requestBody = json_decode(file_get_contents('php://input'));

      if (!$requestBody) {
        http_response_code(400);
        $response->message = 'Invalid or missing JSON request body.';
        echo json_encode($response);
        exit;
      }

      if (!isset($requestBody->id) || !is_numeric($requestBody->id)) {
        http_response_code(400);
        $response->message = 'Missing or invalid required field: id';
        echo json_encode($response);
        exit;
      }

      $id = (int) $requestBody->id;
      $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

      // Fetch existing artifact scoped to user
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

      // Merge allowed fields from request body
      $allowed_fields = [
        'Access', 'Acq', 'Age', 'age_max', 'Av', 'BGG_Rat', 'Candidate',
        'FavCt', 'FullTitle', 'is_digital', 'is_in_secondary_collection',
        'is_kept', 'is_physical', 'MnP',
        'MnT', 'MxP', 'MxT', 'OrigPlat', 'SS', 'System', 'Title',
        'to_get_rid_of', 'type', 'UsedRecUserCt', 'Wt', 'Yr'
      ];

      $update_data = [];
      foreach ($allowed_fields as $field) {
        if (isset($requestBody->$field)) {
          $update_data[$field] = $requestBody->$field;
        }
      }

      $artifact->merge_attributes($update_data);

      if ($user_id) {
        $result = $artifact->save_by_user_id();
      } else {
        $result = $artifact->save();
      }

      if ($result === true) {
        $logger->logDataChange('update', 'artifact', $artifact->id, ['title' => $artifact->Title]);
        $response->message = 'Item updated successfully.';
        $response->artifact = $artifact;
        echo json_encode($response);
      } elseif (is_string($result)) {
        // save_by_user_id returns a string message on not-found
        http_response_code(404);
        $response->message = $result;
        echo json_encode($response);
      } else {
        http_response_code(422);
        $response->message = 'Failed to update item.';
        $response->errors = $artifact->errors;
        echo json_encode($response);
      }
      break;

    case 'DELETE':
      // Agent keys permit reads plus the kept toggle only.
      deny_agent_key_writes($authentication_response);
      // Delete an artifact by ID, scoped to authenticated user
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
        $result = $artifact->delete_by_user_id();
      } else {
        $result = $artifact->delete();
      }

      if ($result === true) {
        $logger->logDataChange('delete', 'artifact', $id, ['title' => $artifact->Title]);
        $response->message = 'Item deleted successfully.';
        echo json_encode($response);
      } elseif (is_string($result)) {
        http_response_code(404);
        $response->message = $result;
        echo json_encode($response);
      } else {
        http_response_code(500);
        $response->message = 'Failed to delete item.';
        echo json_encode($response);
      }
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET, POST, PUT, DELETE';
      echo json_encode($response);
      break;
  }

?>
