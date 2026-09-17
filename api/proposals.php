<?php

  // Proposal history reads for remote agents (spec #10, ticket #18).
  // GET with optional ?start=YYYY-MM-DD&end=YYYY-MM-DD&include_other=1&
  // sort=item_name|explicit_declines|chose_something_else&direction=asc|desc

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('proposals', ['method' => $_SERVER['REQUEST_METHOD']]);

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

  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    $response->message = 'Method not allowed. Supported methods: GET';
    echo json_encode($response);
    exit;
  }

  $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;
  if (!$user_id) {
    if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
      $user_id = (int) $_GET['user_id'];
    } else {
      http_response_code(400);
      $response->message = 'Missing required parameter: user_id';
      echo json_encode($response);
      exit;
    }
  }

  $start = isset($_GET['start']) ? (string) $_GET['start'] : '';
  $end = isset($_GET['end']) ? (string) $_GET['end'] : '';
  $include_other = isset($_GET['include_other']) && $_GET['include_other'] !== '0' && $_GET['include_other'] !== '';
  $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'explicit_declines';
  $direction = isset($_GET['direction']) ? (string) $_GET['direction'] : 'desc';

  $proposals = new ProposalOutcomes($database, $user_id);
  try {
    $response->proposals = $proposals->report($start, $end, $include_other, $sort, $direction);
  } catch (InvalidArgumentException $e) {
    http_response_code(400);
    $response->message = $e->getMessage();
    echo json_encode($response);
    exit;
  }
  $response->user_id = $user_id;
  echo json_encode($response);

?>
