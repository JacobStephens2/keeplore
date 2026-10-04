<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Accounts, sending its mail over SMTP.
function accounts() {
  global $db;
  return new Accounts($db, SmtpMailer::fromEnvironment());
}

// Logs in the account Accounts returned: the session, the remember-me
// window and the JWT cookie.
function log_in_user($account, $remember = false) {
  // Renerating the ID protects the admin from session fixation.
  session_regenerate_id();

  // 30 days if "Remember me" is checked, otherwise 24 hours
  $expiry_seconds = $remember ? 2592000 : 86400;
  $expiry_minutes = $expiry_seconds / 60;

  put_account_in_session($account);

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
      'user_id' => $account['id'],
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

// Records the account as the session's owner, with its person_id as the
// person who is the owner themself.
function put_account_in_session($account) {
  $_SESSION['FullName'] = $account['name'];
  $_SESSION['user_id'] = $account['id'];
  $_SESSION['player_id'] = $account['person_id'];
  $_SESSION['last_login'] = time();
  $_SESSION['username'] = $account['username'];
  $_SESSION['user_group'] = $account['user_group'];
  $_SESSION['logged_in'] = true;
}

function is_logged_in() {
  if (isset($_SESSION['user_id'])) {
    if (empty($_SESSION['player_id']) && empty($_SESSION['guest_mode'])) {
      $_SESSION['player_id'] = accounts()->find((int) $_SESSION['user_id'])['person_id'] ?? null;
    }
    return true;
  }
  // Restore session from JWT if PHP session expired but token is still valid
  if (isset($_COOKIE['access_token'])) {
    try {
      $decoded = JWT::decode($_COOKIE['access_token'], new Key(JWT_SECRET, 'HS256'));
      if (isset($decoded->user_id)) {
        $account = accounts()->find((int) $decoded->user_id);
        if ($account) {
          session_regenerate_id();
          put_account_in_session($account);
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
