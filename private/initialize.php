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

  ob_start(); // output buffering is turned on

  // Assign file paths to PHP constants
  // __FILE__ returns the current path to this file
  // dirname() returns the path to the parent directory
  define("PRIVATE_PATH", dirname(__FILE__));
  define("PROJECT_PATH", dirname(PRIVATE_PATH));
  define("PUBLIC_PATH", PROJECT_PATH . '/artifacts');
  define("SHARED_PATH", PRIVATE_PATH . '/shared');

  // Assign the root URL to a PHP constant
  // * Do not need to include the domain
  // * Use same document root as webserver
  // * Can set a hardcoded value:
  // define("WWW_ROOT", '');
  // * Can dynamically find everything in URL up to "/public"
  // $public_end = strpos($_SERVER['SCRIPT_NAME'], '/artifacts') + 10;
  // $doc_root = substr($_SERVER['SCRIPT_NAME'], 0, $public_end);
  // define("WWW_ROOT", $doc_root);

  define("WWW_ROOT", '');

  require_once(__DIR__ . '/vendor/autoload.php');
  require_once('environment_variables.php');

  // Session + cookies use ARTIFACTS_DOMAIN (e.g. keeplore.app) so auth
  // survives across the canonical host. Load env first so domain is known.
  $session_lifetime = 86400; // 24 hours, matching JWT token expiry
  ini_set('session.gc_maxlifetime', $session_lifetime);
  $session_cookie = [
    'lifetime' => $session_lifetime,
    'path' => '/',
    'secure' => COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax',
  ];
  if (ARTIFACTS_DOMAIN !== '') {
    $session_cookie['domain'] = ARTIFACTS_DOMAIN;
  }
  session_set_cookie_params($session_cookie);
  session_start();

  require_once('functions.php');
  require_once('database.php');
  require_once('kept_status.php');
  require_once('agent_keys.php');
  require_once('agent_api_docs.php');
  require_once('query_functions.php');
  require_once('validation_functions.php');
  require_once('auth_functions.php');
  require_once('app_logger.php');
  require_once('cache.php');

  $db = db_connect();
  $errors = [];

  // Load OOP data access layer (shared with API)
  require_once('classes/DatabaseObject.class.php');
  DatabaseObject::set_database($db);
  require_once('classes/Artifact.class.php');
  require_once('classes/User.class.php');

  $logger = new AppLogger();
  $cache = new Cache();

  // Generate CSRF token for all pages
  generate_csrf_token();

  // Validate CSRF token on all POST requests
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && php_sapi_name() !== 'cli') {
    if (!validate_csrf_token()) {
      http_response_code(403);
      exit('Invalid CSRF token.');
    }
  }

?>
