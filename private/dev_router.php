<?php

/**
 * PHP built-in server rewrite helper. Mirrors ui/.htaccess:
 * extensionless paths become .php when that file exists.
 *
 * Returns:
 * - false: let the built-in server serve the existing file
 * - string: PHP script to require
 * - null: 404
 *
 * An existing file is left to the server, and a script path is returned for
 * an extensionless script or a directory index (including the docroot index
 * for "/"), only when that file's real path stays inside the docroot. A
 * candidate that resolves outside returns null.
 */
function keeplore_ui_router_script($uri, $docroot) {
  $docroot = rtrim($docroot, '/');
  $realPathInsideDocroot = function ($candidate) use ($docroot) {
    $realCandidate = realpath($candidate);
    $realDocroot = realpath($docroot);
    if (!is_string($realCandidate) || !is_string($realDocroot)) {
      return false;
    }
    return str_starts_with($realCandidate, $realDocroot . '/');
  };

  $path = parse_url((string) $uri, PHP_URL_PATH);
  if (!is_string($path) || $path === '' || $path === '/') {
    $index = $docroot . '/index.php';
    if (is_file($index) && !$realPathInsideDocroot($index)) {
      return null;
    }
    return $index;
  }

  $direct = $docroot . $path;
  if (is_file($direct)) {
    return $realPathInsideDocroot($direct) ? false : null;
  }
  if (is_dir($direct)) {
    $index = rtrim($direct, '/') . '/index.php';
    if (is_file($index)) {
      return $realPathInsideDocroot($index) ? $index : null;
    }
  }

  if (!str_contains(basename($path), '.')) {
    $php = $direct . '.php';
    if (is_file($php)) {
      return $realPathInsideDocroot($php) ? $php : null;
    }
  }

  return null;
}
