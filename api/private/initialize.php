<?php

if (getenv('APP_ENV') === 'development') {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', 0);
  ini_set('display_startup_errors', 0);
  error_reporting(E_ALL);
  ini_set('log_errors', 1);
}

require_once('../private/environment_variables.php');

$keeplore_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$keeplore_origin_host = $keeplore_origin !== '' ? parse_url($keeplore_origin, PHP_URL_HOST) : '';
if ($keeplore_origin_host === '127.0.0.1' || $keeplore_origin_host === 'localhost') {
  header('Access-Control-Allow-Origin: ' . $keeplore_origin);
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Credentials: true');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

require_once('../private/vendor/autoload.php');

require_once('../private/auth_functions.php');

include_once('database_functions.php');
$database = db_connect();

// Kept-status seam and agent-key helpers (shared with the UI bootstrap)
require_once('../private/kept_status.php');
require_once('../private/item_tags.php');
require_once('../private/agent_keys.php');
require_once('../private/agent_api_docs.php');

require_once('../private/classes/DatabaseObject.class.php');
DatabaseObject::set_database($database);

// Classes that extend DatabaseObject
require_once('../private/classes/Artifact.class.php');
require_once('../private/classes/User.class.php');
require_once('../private/classes/ProposalOutcomes.php');

?>