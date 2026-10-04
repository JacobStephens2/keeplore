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

  $preferences = (new Preferences($database, $user_id))->get();
  $default_interval = $preferences['default_use_interval'];
  $prefs = [
    'enabled' => $preferences['native_notify_enabled'],
    'hour' => $preferences['native_notify_hour'],
    'lead_days' => $preferences['native_notify_lead_days'],
    'past_due' => $preferences['native_notify_past_due'],
  ];

  $queue = new UseByQueue($database, $user_id);

  $items = [];
  foreach ($queue->entries(['default_interval' => $default_interval]) as $entry) {
    if ($entry['use_by_date'] === null || $entry['days_until'] > 60) {
      continue;
    }

    $items[] = [
      'id' => (int) $entry['id'],
      'title' => $entry['Title'],
      'use_by_date' => $entry['use_by_date'],
      'most_recent_interaction' => $entry['last_use'],
      'interval_days' => ($entry['interaction_frequency_days'] !== null)
        ? (float) $entry['interaction_frequency_days']
        : $default_interval,
      'status' => match (true) {
        $entry['status'] === 'overdue' => 'past_due',
        $entry['status'] === 'due_today' => 'due_today',
        $entry['days_until'] <= 7 => 'due_soon',
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
