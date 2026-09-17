<?php

  require_once('private/initialize.php');

  header('Content-Type: application/json');

  $response = new stdClass;

  $response->message = 'Hello from the Keeplore API.';

  // Remote agents authenticate with a per-agent per-user key issued under
  // Settings > Agent API keys, sent as an Authorization: Bearer header.
  // Agent keys permit reads plus the kept toggle only.
  $response->agent_auth = 'Authorization: Bearer <agent-key> (see /settings/agent-keys.php)';
  $response->endpoints = array(
    'GET /artifacts.php' => 'https://' . API_ORIGIN . '/artifacts.php',
    'GET /artifact.php?id=<id>' => 'https://' . API_ORIGIN . '/artifact.php',
    'GET /uses.php' => 'https://' . API_ORIGIN . '/uses.php',
    'GET /proposals.php' => 'https://' . API_ORIGIN . '/proposals.php',
    'POST /artifact-kept.php' => 'https://' . API_ORIGIN . '/artifact-kept.php'
  );

  echo json_encode($response);

?>