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

  // The options array is what can set SameSite. Strict keeps this cookie
  // off a cross-site request, including a top-level GET.
  setcookie('access_token', $access_token, [
      'expires' => time() + $expiry_seconds,
      'path' => '/',
      'domain' => ARTIFACTS_DOMAIN,
      'secure' => COOKIE_SECURE,
      'httponly' => true,
      'samesite' => 'Strict',
  ]);
  return true;
}

// Logging out and browsing as guest both leave the Account, so both drop
// its access token cookie. An empty value in the past is the cookie the
// browser deletes.
function expire_access_token() {
  setcookie('access_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => ARTIFACTS_DOMAIN,
    'secure' => COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
}

// Performs all actions necessary to log out an admin
function log_out() {
  global $logger;
  if (isset($logger)) {
    $logger->logAuth('logout', ['user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null]);
  }
  $_SESSION = [];
  session_destroy();
  expire_access_token();

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
  global $db;
  if (isset($_SESSION['user_id'])) {
    // A guest session is not a login, so it has no last-login time to compare.
    // An account session with no last-login was not issued by a login; once a
    // password change is recorded it cannot be shown to be later.
    if (empty($_SESSION['guest_mode'])) {
      if (!Accounts::issuedAtAuthenticates($db, (int) $_SESSION['user_id'], $_SESSION['last_login'] ?? null)) {
        // The header reads this flag, not is_logged_in's return, for the nav.
        $_SESSION['logged_in'] = false;
        return false;
      }
    }
    if (empty($_SESSION['player_id']) && empty($_SESSION['guest_mode'])) {
      $_SESSION['player_id'] = accounts()->find((int) $_SESSION['user_id'])['person_id'] ?? null;
    }
    return true;
  }
  // Restore session from JWT if PHP session expired but token is still valid.
  // Keep the token's issued-at as last-login. Stamping now would make the
  // reset request's own restore look newer than the password change.
  if (isset($_COOKIE['access_token'])) {
    try {
      $decoded = JWT::decode($_COOKIE['access_token'], new Key(JWT_SECRET, 'HS256'));
      if (isset($decoded->user_id)) {
        $issuedAt = $decoded->iat ?? null;
        if (!Accounts::issuedAtAuthenticates($db, (int) $decoded->user_id, $issuedAt)) {
          return false;
        }
        $account = accounts()->find((int) $decoded->user_id);
        if ($account) {
          session_regenerate_id();
          put_account_in_session($account);
          // The restored session was issued when the token was, not now.
          if ($issuedAt !== null) {
            $_SESSION['last_login'] = (int) $issuedAt;
          }
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
  // The Account's access token must not authenticate the next request
  // once the browser is only a guest.
  expire_access_token();
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
