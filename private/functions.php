<?php

// CSRF Protection
function generate_csrf_token() {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_input() {
  $token = generate_csrf_token();
  return '<input type="hidden" name="csrf_token" value="' . h($token) . '">';
}

function validate_csrf_token() {
  if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
    return false;
  }
  return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function url_for($script_path) {
  // add the leading '/' if not present
  if($script_path[0] != '/') {
    $script_path = "/" . $script_path;
  }
  return WWW_ROOT . $script_path;
}

function u($string="") {
  return urlencode($string);
}

function raw_u($string="") {
  return rawurlencode($string);
}

function h($string="") {
  return htmlspecialchars($string ?? '');
}

function normalize_item_image_url($value) {
  $url = trim((string) $value);
  if ($url === '' || strlen($url) > 1024 || !preg_match('#^https://[^\s]+$#i', $url)) {
    return '';
  }
  return $url;
}

// BGG-family item pages. A typed "boardgamegeek.com/..." or http:// link
// is upgraded to https; anything else normalizes to ''.
function normalize_item_bgg_url($value) {
  $url = trim((string) $value);
  $url = preg_replace('#^(https?://)?#i', 'https://', $url, 1);
  if (strlen($url) > 1024
    || !preg_match('#^https://(www\.)?(boardgamegeek|rpggeek|videogamegeek)\.com(/[^\s]*)?$#i', $url)) {
    return '';
  }
  return $url;
}

const ITEM_BGG_AGE_BASES = ['community', 'publisher'];

// The BGG columns an item write stores. The vote basis only means something
// alongside a link, so an item without one stores none.
function item_bgg_fields_for_storage($artifact) {
  $url = normalize_item_bgg_url($artifact['bgg_url'] ?? '');
  if ($url === '') {
    return ['bgg_url' => null, 'bgg_player_votes' => null, 'bgg_age_basis' => null];
  }
  $votes = trim((string) ($artifact['bgg_player_votes'] ?? ''));
  $age_basis = $artifact['bgg_age_basis'] ?? null;
  return [
    'bgg_url' => $url,
    'bgg_player_votes' => preg_match('/^\d{1,9}$/', $votes) ? (int) $votes : null,
    'bgg_age_basis' => in_array($age_basis, ITEM_BGG_AGE_BASES, true) ? $age_basis : null,
  ];
}

function item_bgg_link_html($value) {
  $url = normalize_item_bgg_url($value);
  if ($url === '') {
    return '';
  }
  $site = 'BoardGameGeek';
  if (preg_match('#^https://(www\.)?rpggeek\.com#i', $url)) {
    $site = 'RPGGeek';
  } elseif (preg_match('#^https://(www\.)?videogamegeek\.com#i', $url)) {
    $site = 'VideoGameGeek';
  }
  return '<p><a class="item-bgg-link" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">View on ' . $site . '</a></p>';
}

// What an item's BGG numbers rest on. Player counts and Sweet Spot share
// BGG's player-count poll; BGG does not publish the age poll's vote count.
// Only the parts that are known are described.
function item_bgg_basis_text($player_votes, $age_basis) {
  $parts = [];
  if ($player_votes !== null && $player_votes !== '') {
    $votes = (int) $player_votes;
    $parts[] = $votes > 0
      ? 'player counts and Sweet Spot from ' . $votes . ' BGG community ' . ($votes === 1 ? 'vote' : 'votes')
      : 'player counts from the BGG publisher listing (no community recommendation)';
  }
  if ($age_basis === 'community') {
    $parts[] = 'minimum age from the BGG community age poll';
  } elseif ($age_basis === 'publisher') {
    $parts[] = 'minimum age from the BGG publisher listing';
  }
  return $parts === [] ? '' : ucfirst(implode('; ', $parts)) . '.';
}

function item_bgg_basis_html($item) {
  $text = item_bgg_basis_text($item['bgg_player_votes'] ?? null, $item['bgg_age_basis'] ?? null);
  return $text === '' ? '' : '<p class="item-bgg-basis">' . h($text) . '</p>';
}

function error_404() {
  header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
  exit();
}

function error_500() {
  header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
  exit();
}

function redirect_to($location) {
  header("Location: " . $location);
  exit;
}

function is_post_request() {
  return $_SERVER['REQUEST_METHOD'] == 'POST';
}

function is_get_request() {
  return $_SERVER['REQUEST_METHOD'] == 'GET';
}

// Display-mode preference (system / light / dark).
// The stored value lives in the browser (localStorage `keeplore-theme`);
// This module owns the value vocabulary: header.php renders it into the
// #theme-switcher select, and theme.js reads the allowed values back out of
// that DOM (the cross-language seam), so the list exists in exactly one place.
function theme_default() {
  return 'system';
}

function theme_options() {
  return ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'];
}

function theme_sanitize($value) {
  return (is_string($value) && array_key_exists($value, theme_options()))
    ? $value
    : theme_default();
}

function display_errors($errors=array()) {
  $output = '';
  if(!empty($errors)) {
    $output .= "<div class=\"errors\">";
    $output .= "Please fix the following errors:<ul style='margin: 0; padding: 0; '>";
    foreach($errors as $error) {
      $output .= "<li>" . h($error) . "</li>";
    }
    $output .= "</ul>";
    $output .= "</div>";
  }
  return $output;
}

function get_and_clear_session_message() {
  if(isset($_SESSION['message']) && $_SESSION['message'] != '') {
    $msg = $_SESSION['message'];
    unset($_SESSION['message']);
    return $msg;
  }
}

function display_session_message() {
  $msg = get_and_clear_session_message();
  if (is_blank($msg)) {
    return '';
  }
  return '<div id="flash-toast" class="toast toast-success" role="status" aria-live="polite">'
    . h($msg)
    . '</div>'
    . '<script>(function(){var t=document.getElementById("flash-toast");if(!t)return;'
    . 'requestAnimationFrame(function(){t.classList.add("is-visible");});'
    . 'setTimeout(function(){t.classList.remove("is-visible");setTimeout(function(){t.remove();},250);},4500);'
    . 't.addEventListener("click",function(){t.classList.remove("is-visible");setTimeout(function(){t.remove();},250);});'
    . '})();</script>';
}

?>
