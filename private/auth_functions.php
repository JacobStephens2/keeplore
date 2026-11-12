<?php

require_once __DIR__ . '/classes/AccountAccessTokens.php';

// Accounts, sending its mail over SMTP.
function accounts() {
  global $db;
  return new Accounts($db, SmtpMailer::fromEnvironment());
}

// Logs in the account Accounts returned: the session, the remember-me
// window and the JWT cookie.
function log_in_user($account, $remember = false) {
  $issued = AccountAccessTokens::issue($account['id'], $_SERVER['SERVER_NAME'], $remember);

  // PHP only accepts lifetime settings while the session is inactive.
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
  }
  ini_set('session.gc_maxlifetime', $issued['lifetime']);
  session_set_cookie_params([
    'lifetime' => $issued['lifetime'],
    'path' => '/',
    'domain' => ARTIFACTS_DOMAIN,
    'secure' => COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
  // Regenerating the ID protects the account from session fixation.
  session_regenerate_id();
  put_account_in_session($account);

  // Strict keeps this cookie off a cross-site request, including a top-level GET.
  set_access_token_cookie($issued['token'], $issued['expires_at']);
  return true;
}

// Logging out and browsing as guest both leave the Account, so both drop
// its access token cookie. An empty value in the past is the cookie the
// browser deletes.
function expire_access_token() {
  set_access_token_cookie('', time() - 3600);
}

// Login and logout share one access-token cookie. The options array is
// what can set SameSite, and both calls have to stay Strict.
function set_access_token_cookie($value, $expires) {
  setcookie('access_token', $value, [
    'expires' => $expires,
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
      $identity = AccountAccessTokens::authenticate($db, $_COOKIE['access_token']);
      if ($identity !== null) {
        $account = accounts()->find($identity['user_id']);
        if ($account) {
          session_regenerate_id();
          put_account_in_session($account);
          // The restored session was issued when the token was, not now.
          if ($identity['issued_at'] !== null) {
            $_SESSION['last_login'] = $identity['issued_at'];
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

// Browse as guest, Logout, and Exit guest mode. Reads Sec-Fetch-Site
// from this request. cross-site is cross_site_request_refusal() and the
// session stays as it was. same-site, same-origin, and none run the
// action and return null. Exit guest mode is logout while this session
// is already a guest. Any other $action is not one of these.
function run_guest_or_logout(string $action): ?array {
  if ($action !== 'guest' && $action !== 'logout') {
    return null;
  }
  if ($action === 'guest') {
    $cannot = 'browse as guest';
  } elseif (is_guest()) {
    $cannot = 'exit guest mode';
  } else {
    $cannot = 'log out';
  }
  $refusal = cross_site_request_refusal(sec_fetch_site_header(), $cannot);
  if ($refusal !== null) {
    return $refusal;
  }
  if ($action === 'guest') {
    start_guest_session();
    return null;
  }
  log_out();
  return null;
}

// Login and logout both stop on that refusal. What they do when the
// action is allowed is not the same, so that stays with the caller.
function exit_on_guest_or_logout_refusal(string $action): void {
  $refusal = run_guest_or_logout($action);
  if ($refusal === null) {
    return;
  }
  http_response_code($refusal[0]);
  exit($refusal[1]['message']);
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
