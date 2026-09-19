<?php

/**
 * PHP built-in server rewrite helper. Mirrors ui/.htaccess:
 * extensionless paths become .php when that file exists.
 *
 * Returns:
 * - false: let the built-in server serve the existing file
 * - string: PHP script to require
 * - null: 404
 */
function keeplore_ui_router_script($uri, $docroot) {
  $path = parse_url((string) $uri, PHP_URL_PATH);
  if (!is_string($path) || $path === '' || $path === '/') {
    return rtrim($docroot, '/') . '/index.php';
  }

  $docroot = rtrim($docroot, '/');
  $direct = $docroot . $path;
  if (is_file($direct)) {
    return false;
  }

  if (!str_contains(basename($path), '.')) {
    $php = $direct . '.php';
    if (is_file($php)) {
      return $php;
    }
  }

  return null;
}
