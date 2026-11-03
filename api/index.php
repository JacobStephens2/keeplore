<?php

  require_once('private/initialize.php');

  header('Content-Type: application/json');

  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  if ($path === '/api/health' || $path === '/health') {
    require_once('../private/release_health.php');
    $healthy = release_schema_is_ready($database);
    http_response_code($healthy ? 200 : 503);
    echo json_encode($healthy
      ? ['healthy' => true]
      : ['healthy' => false, 'message' => 'Required database schema is unavailable. Apply release migrations before promotion.']);
    return;
  }

  echo json_encode(agent_api_discovery());

?>
