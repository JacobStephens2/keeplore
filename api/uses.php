<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
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

  $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;

  $method = $_SERVER['REQUEST_METHOD'];

  switch ($method) {

    case 'GET':
      // List uses for authenticated user, with optional artifact_id filter
      if ($user_id) {
        if (isset($_GET['artifact_id']) && is_numeric($_GET['artifact_id'])) {
          $artifact_id = (int) $_GET['artifact_id'];
          $stmt = $database->prepare(
            "SELECT uses.id, uses.artifact_id, uses.use_date, uses.note, uses.notesTwo,
                    games.Title AS artifact_title
             FROM uses
             LEFT JOIN games ON uses.artifact_id = games.id
             WHERE uses.user_id = ? AND uses.artifact_id = ?
             ORDER BY uses.use_date DESC, uses.id DESC"
          );
          $stmt->bind_param("ii", $user_id, $artifact_id);
        } else {
          $stmt = $database->prepare(
            "SELECT uses.id, uses.artifact_id, uses.use_date, uses.note, uses.notesTwo,
                    games.Title AS artifact_title
             FROM uses
             LEFT JOIN games ON uses.artifact_id = games.id
             WHERE uses.user_id = ?
             ORDER BY uses.use_date DESC, uses.id DESC"
          );
          $stmt->bind_param("i", $user_id);
        }
      } else {
        // API key auth without user_id: require artifact_id filter
        if (isset($_GET['artifact_id']) && is_numeric($_GET['artifact_id'])) {
          $artifact_id = (int) $_GET['artifact_id'];
          $stmt = $database->prepare(
            "SELECT uses.id, uses.artifact_id, uses.use_date, uses.note, uses.notesTwo,
                    games.Title AS artifact_title
             FROM uses
             LEFT JOIN games ON uses.artifact_id = games.id
             WHERE uses.artifact_id = ?
             ORDER BY uses.use_date DESC, uses.id DESC"
          );
          $stmt->bind_param("i", $artifact_id);
        } else {
          http_response_code(400);
          $response->message = 'artifact_id parameter is required for API key authentication.';
          echo json_encode($response);
          exit;
        }
      }

      $stmt->execute();
      $result = $stmt->get_result();
      $uses = [];
      while ($record = $result->fetch_assoc()) {
        $uses[] = $record;
      }
      $stmt->close();

      $response->uses = $uses;
      echo json_encode($response);
      break;

    case 'POST':
      // Agent keys permit reads plus the kept toggle only.
      deny_agent_key_writes($authentication_response);
      // Record a new use
      $requestBody = json_decode(file_get_contents('php://input'));

      if (!$requestBody) {
        http_response_code(400);
        $response->message = 'Invalid or missing JSON request body.';
        echo json_encode($response);
        exit;
      }

      // Validate required fields
      if (!isset($requestBody->artifact_id) || !is_numeric($requestBody->artifact_id)) {
        http_response_code(400);
        $response->message = 'artifact_id is required and must be numeric.';
        echo json_encode($response);
        exit;
      }

      if (!isset($requestBody->use_date) || trim($requestBody->use_date) === '') {
        http_response_code(400);
        $response->message = 'use_date is required (YYYY-MM-DD format).';
        echo json_encode($response);
        exit;
      }

      // Validate date format
      $date = DateTime::createFromFormat('Y-m-d', $requestBody->use_date);
      if (!$date || $date->format('Y-m-d') !== $requestBody->use_date) {
        http_response_code(400);
        $response->message = 'use_date must be a valid date in YYYY-MM-DD format.';
        echo json_encode($response);
        exit;
      }

      $artifact_id = (int) $requestBody->artifact_id;
      $use_date = $requestBody->use_date;
      $note = isset($requestBody->note) ? $requestBody->note : '';
      $notesTwo = isset($requestBody->notesTwo) ? $requestBody->notesTwo : '';

      // Determine the user_id for the new record
      $record_user_id = $user_id ? $user_id : (isset($requestBody->user_id) ? (int) $requestBody->user_id : null);

      if (!$record_user_id) {
        http_response_code(400);
        $response->message = 'Could not determine user_id for this use record.';
        echo json_encode($response);
        exit;
      }

      // Verify the artifact exists and belongs to the user
      if ($user_id) {
        $artifact = Artifact::find_by_id_and_user_id($artifact_id, $user_id);
        if (!$artifact) {
          http_response_code(404);
          $response->message = 'Item not found or does not belong to you.';
          echo json_encode($response);
          exit;
        }
      }

      $stmt = $database->prepare(
        "INSERT INTO uses (artifact_id, use_date, user_id, note, notesTwo) VALUES (?, ?, ?, ?, ?)"
      );
      $stmt->bind_param("isiss", $artifact_id, $use_date, $record_user_id, $note, $notesTwo);
      $result = $stmt->execute();

      if ($result) {
        $new_id = $database->insert_id;
        $stmt->close();

        http_response_code(201);
        $logger->logDataChange('create', 'use', $new_id, [
          'artifact_id' => $artifact_id,
          'use_date' => $use_date
        ]);
        $response->message = 'Use recorded successfully.';
        $response->use = [
          'id' => $new_id,
          'artifact_id' => $artifact_id,
          'use_date' => $use_date,
          'user_id' => $record_user_id,
          'note' => $note,
          'notesTwo' => $notesTwo
        ];
        echo json_encode($response);
      } else {
        $stmt->close();
        http_response_code(500);
        $response->message = 'Failed to record use.';
        echo json_encode($response);
      }
      break;

    case 'DELETE':
      // Agent keys permit reads plus the kept toggle only.
      deny_agent_key_writes($authentication_response);
      // Delete a use record by ID, scoped to authenticated user
      if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        $response->message = 'Missing or invalid required parameter: id';
        echo json_encode($response);
        exit;
      }

      $id = (int) $_GET['id'];

      if ($user_id) {
        // Verify ownership before deleting
        $check_stmt = $database->prepare(
          "SELECT id FROM uses WHERE id = ? AND user_id = ?"
        );
        $check_stmt->bind_param("ii", $id, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows === 0) {
          $check_stmt->close();
          http_response_code(404);
          $response->message = 'Use record not found.';
          echo json_encode($response);
          exit;
        }
        $check_stmt->close();

        // Delete the use record
        $stmt = $database->prepare("DELETE FROM uses WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $id, $user_id);
      } else {
        // API key auth: delete by id only
        $check_stmt = $database->prepare("SELECT id FROM uses WHERE id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows === 0) {
          $check_stmt->close();
          http_response_code(404);
          $response->message = 'Use record not found.';
          echo json_encode($response);
          exit;
        }
        $check_stmt->close();

        $stmt = $database->prepare("DELETE FROM uses WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
      }

      $result = $stmt->execute();
      $stmt->close();

      if ($result) {
        $logger->logDataChange('delete', 'use', $id);
        $response->message = 'Use record deleted successfully.';
        echo json_encode($response);
      } else {
        http_response_code(500);
        $response->message = 'Failed to delete use record.';
        echo json_encode($response);
      }
      break;

    default:
      http_response_code(405);
      $response->message = 'Method not allowed. Supported methods: GET, POST, DELETE';
      echo json_encode($response);
      break;
  }

?>
