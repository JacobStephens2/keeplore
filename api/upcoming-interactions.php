<?php

  require_once('private/initialize.php');
  require_once('../private/rate_limiter.php');
  require_once('../private/app_logger.php');
  require_once('../private/query_functions.php');
  require_once('../private/classes/UseByQueue.php');
  header('Content-Type: application/json');

  $logger = new AppLogger();
  $logger->logApiRequest('upcoming-interactions', ['method' => $_SERVER['REQUEST_METHOD']]);

  $response = new stdClass;

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

  $user_id = isset($authentication_response->user_id) ? (int) $authentication_response->user_id : null;
  if (!$user_id) {
    http_response_code(400);
    $response->message = 'This endpoint requires user-scoped authentication (JWT cookie).';
    echo json_encode($response);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    $response->message = 'Method not allowed. Supported methods: GET';
    echo json_encode($response);
    exit;
  }

  $default_interval = default_use_interval($database, $user_id);
  $user_stmt = mysqli_prepare($database, "SELECT native_notify_enabled, native_notify_hour, native_notify_lead_days, native_notify_past_due FROM users WHERE id = ?");
  mysqli_stmt_bind_param($user_stmt, "i", $user_id);
  mysqli_stmt_execute($user_stmt);
  $user_result = mysqli_stmt_get_result($user_stmt);
  $user_row = mysqli_fetch_assoc($user_result);
  mysqli_stmt_close($user_stmt);
  $prefs = [
    'enabled' => (int) ($user_row['native_notify_enabled'] ?? 1) === 1,
    'hour' => (int) ($user_row['native_notify_hour'] ?? 9),
    'lead_days' => (int) ($user_row['native_notify_lead_days'] ?? 3),
    'past_due' => (int) ($user_row['native_notify_past_due'] ?? 1) === 1,
  ];

  $queue = new UseByQueue($database, $user_id);

  $items = [];
  foreach ($queue->entries(['default_interval' => $default_interval]) as $artifact) {
    if ($artifact['use_by_date'] === null || $artifact['days_until'] > 60) {
      continue;
    }

    $items[] = [
      'id' => (int) $artifact['id'],
      'title' => $artifact['Title'],
      'use_by_date' => $artifact['use_by_date'],
      'most_recent_interaction' => $artifact['last_use'],
      'interval_days' => ($artifact['interaction_frequency_days'] !== null)
        ? (float) $artifact['interaction_frequency_days']
        : $default_interval,
      'status' => match (true) {
        $artifact['status'] === 'overdue' => 'past_due',
        $artifact['status'] === 'due_today' => 'due_today',
        $artifact['days_until'] <= 7 => 'due_soon',
        default => 'upcoming',
      },
    ];
  }

  $response->authenticated = true;
  $response->today = $queue->today();
  $response->timezone = 'America/New_York';
  $response->horizon_days = 60;
  $response->default_interval_days = $default_interval;
  $response->notification_prefs = $prefs;
  $response->items = $items;

  echo json_encode($response);
