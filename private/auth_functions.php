<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Performs all actions necessary to log in an admin
function log_in_user($user, $remember = false) {
  // Renerating the ID protects the admin from session fixation.
  session_regenerate_id();

  global $db;

  $stmt = mysqli_prepare($db, "SELECT * FROM users WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $user['id']);
  mysqli_stmt_execute($stmt);
  $userResultObject = mysqli_stmt_get_result($stmt);
  $userArray = mysqli_fetch_assoc($userResultObject);
  mysqli_stmt_close($stmt);

  // 30 days if "Remember me" is checked, otherwise 24 hours
  $expiry_seconds = $remember ? 2592000 : 86400;
  $expiry_minutes = $expiry_seconds / 60;

  $_SESSION['FullName'] = $userArray['first_name'] . ' ' . $userArray['last_name'];
  $_SESSION['user_id'] = $user['id'];
  $_SESSION['player_id'] = $user['player_id'];
  if (empty($_SESSION['player_id'])) {
    $_SESSION['player_id'] = backfill_user_player_id($user['id']);
  }
  $_SESSION['last_login'] = time();
  $_SESSION['username'] = $user['username'];
  $_SESSION['user_group'] = $user['user_group'];
  $_SESSION['logged_in'] = true;

  // Extend PHP session lifetime to match JWT / remember-me window
  ini_set('session.gc_maxlifetime', $expiry_seconds);
  session_set_cookie_params([
    'lifetime' => $expiry_seconds,
    'path' => '/',
    'domain' => ARTIFACTS_DOMAIN,
    'secure' => COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  // Create JWT access token cookie for response
  $issuedAt   = new DateTimeImmutable();
  $jwt_access_token_data = [
      // Issued at: time when the token was generated
      'iat'  => $issuedAt->getTimestamp(),
      'iss'  => $_SERVER['SERVER_NAME'], // Issuer
      'nbf'  => $issuedAt->getTimestamp(), // Not before
      'exp'  => $issuedAt->modify('+' . $expiry_minutes . ' minutes')->getTimestamp(),
      'user_id' => $user['id'],
  ];
  $access_token = JWT::encode(
      $jwt_access_token_data,
      JWT_SECRET,
      'HS256'
  );

  setcookie(
      "access_token",         // name
      $access_token,          // value
      time() + $expiry_seconds,
      "/",                    // path
      ARTIFACTS_DOMAIN,       // domain
      COOKIE_SECURE,         // if true, send cookie only to https requests
      true                    // httponly
  ); // End of JWT Access Token Cookie creation
  return true;
}

function request_authorization_header() {
  if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
    return $_SERVER['HTTP_AUTHORIZATION'];
  }
  if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
      return $headers['Authorization'];
    }
  }
  return null;
}

function authenticate() {
  $authorization = request_authorization_header();
  $response = new stdClass;

  if (isset($_COOKIE["access_token"])) {
    try {
      $jwt = $_COOKIE["access_token"];
      $key  = JWT_SECRET;
      $decodedJWT = JWT::decode($jwt, new Key($key, 'HS256'));
      $decodedJWT->authenticated = true;
      $decodedJWT->auth_type = 'session';
      return $decodedJWT;

    } catch (Exception $e) {
      $response->message = 'You have not been authenticated';
      $response->authenticated = false;
      return $response;
    }
  } else {
    // Per-agent per-user keys (spec #10, ticket #18): a Bearer token scoped
    // to one user's reads plus the kept toggle.
    if ($authorization !== null && strncasecmp($authorization, 'Bearer ', 7) === 0) {
      $agent_key = find_agent_key_by_token_or_null(trim(substr($authorization, 7)));
      if ($agent_key !== false) {
        $response->message = 'Your agent key is valid.';
        $response->authenticated = true;
        $response->auth_type = 'agent_key';
        $response->user_id = (int) $agent_key['user_id'];
        $response->agent_key_id = (int) $agent_key['id'];
        $response->agent_name = $agent_key['agent_name'];
        return $response;
      }
      $response->message = 'You have not been authenticated';
      $response->authenticated = false;
      return $response;
    }
    if ($authorization !== null && hash_equals(ARTIFACTS_API_KEY, $authorization)) {
      $response->message = 'Your API Key is valid.';
      $response->authenticated = true;
      $response->auth_type = 'api_key';
      return $response;
    } else {
      $response->message = 'You have not been authenticated';
      return $response;
    }
  }
}

// Looks up a Bearer agent token when a database connection is available;
// returns false without one (e.g. unit tests) or when unknown/revoked.
function find_agent_key_by_token_or_null($token) {
  if ($token === '') {
    return false;
  }
  $conn = null;
  if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof mysqli) {
    $conn = $GLOBALS['db'];
  } elseif (isset($GLOBALS['database']) && $GLOBALS['database'] instanceof mysqli) {
    $conn = $GLOBALS['database'];
  }
  if ($conn === null || !function_exists('find_agent_key_by_token')) {
    return false;
  }
  return find_agent_key_by_token($conn, $token);
}

// Performs all actions necessary to log out an admin
function log_out() {
  global $logger;
  if (isset($logger)) {
    $logger->logAuth('logout', ['user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null]);
  }
  $_SESSION = [];
  session_destroy();

  // Clear the JWT access token cookie
  setcookie(
    "access_token",
    "",
    time() - 3600,
    "/",
    ARTIFACTS_DOMAIN,
    COOKIE_SECURE,
    true
  );

  return true;
}

function backfill_user_player_id($user_id) {
  global $db;
  $stmt = mysqli_prepare($db, "SELECT id FROM players WHERE represents_user_id = ? ORDER BY id ASC LIMIT 1");
  mysqli_stmt_bind_param($stmt, "i", $user_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($result);
  mysqli_stmt_close($stmt);
  if (!$row) {
    return null;
  }
  $player_id = (int) $row['id'];
  $update = mysqli_prepare($db, "UPDATE users SET player_id = ? WHERE id = ? AND (player_id IS NULL OR player_id = 0)");
  mysqli_stmt_bind_param($update, "ii", $player_id, $user_id);
  mysqli_stmt_execute($update);
  mysqli_stmt_close($update);
  return $player_id;
}

function is_logged_in() {
  if (isset($_SESSION['user_id'])) {
    if (empty($_SESSION['player_id']) && empty($_SESSION['guest_mode'])) {
      $_SESSION['player_id'] = backfill_user_player_id($_SESSION['user_id']);
    }
    return true;
  }
  // Restore session from JWT if PHP session expired but token is still valid
  if (isset($_COOKIE['access_token'])) {
    try {
      $decoded = JWT::decode($_COOKIE['access_token'], new Key(JWT_SECRET, 'HS256'));
      if (isset($decoded->user_id)) {
        global $db;
        $stmt = mysqli_prepare($db, "SELECT * FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $decoded->user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if ($user) {
          session_regenerate_id();
          $_SESSION['FullName'] = $user['first_name'] . ' ' . $user['last_name'];
          $_SESSION['user_id'] = $user['id'];
          $_SESSION['player_id'] = $user['player_id'];
          if (empty($_SESSION['player_id'])) {
            $_SESSION['player_id'] = backfill_user_player_id($user['id']);
          }
          $_SESSION['last_login'] = time();
          $_SESSION['username'] = $user['username'];
          $_SESSION['user_group'] = $user['user_group'];
          $_SESSION['logged_in'] = true;
          return true;
        }
      }
    } catch (Exception $e) {
      // JWT invalid or expired — user must log in again
    }
  }
  return false;
}

function is_admin($user_group) {
  return $user_group == 2;
}

// Requires the user logging in at least be in the user group or higher
function require_login() {
    if(!is_logged_in() || $_SESSION['user_group'] < 1) {
      redirect_to(url_for('/login.php?redirectURL=' . urlencode($_SERVER['REQUEST_URI'])));
    }
}

function start_guest_session() {
  $_SESSION = [];
  session_regenerate_id();
  $_SESSION['user_id'] = DEMO_USER_ID;
  $_SESSION['guest_mode'] = true;
  $_SESSION['FullName'] = 'Guest';
  $_SESSION['username'] = 'Guest';
  $_SESSION['logged_in'] = false;
  $_SESSION['user_group'] = 0;
}

function is_guest() {
  return !empty($_SESSION['guest_mode']);
}

function require_login_or_guest() {
  if (!is_logged_in() && !is_guest()) {
    redirect_to(url_for('/login.php?redirectURL=' . urlencode($_SERVER['REQUEST_URI'])));
  }
}

function require_admin() {
  if(!is_logged_in() || $_SESSION['user_group'] < 2) {
    redirect_to(url_for('/login.php'));
  }
}



?>
