<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rate_limiter.php';
require_once __DIR__ . '/app_logger.php';
require_once __DIR__ . '/classes/ApiCaller.php';

/** Maximum accepted HTTP API request body size in bytes (256 KiB). */
const API_MAX_REQUEST_BODY_BYTES = 262144;

/**
 * Answers one HTTP API request for the endpoint named $endpoint. In order:
 * the API rate limit (429), the credential (401, with authenticated false),
 * the method (405, naming $handlers' methods), and the 256 KiB request body
 * limit (413, checked before decoding). Only then is a deferred body read
 * and JSON-decoded, and the handler for the request's method runs with the
 * ApiCaller and the request, returning [status, response fields].
 *
 * $request is api_request_from_globals()'s shape: method, query,
 * content_length and body_stream (or a pre-built body), access_token (the
 * session cookie's, or null), authorization (the Authorization header, or
 * null) and sec_fetch_site (the Sec-Fetch-Site header, or null). The request
 * is logged under $endpoint. An endpoint that isn't $metered skips the rate
 * limit and the log.
 *
 * Returns [status, body], with the body always an object.
 */
function answer_api_request(mysqli $db, string $endpoint, array $handlers, array $request, bool $metered = true): array {
  [$status, $fields] = api_request_status_and_fields($db, $endpoint, $handlers, $request, $metered);
  return [$status, (object) $fields];
}

/** answer_api_request()'s [status, response fields], before the fields become the body. */
function api_request_status_and_fields(mysqli $db, string $endpoint, array $handlers, array $request, bool $metered): array {
  if ($metered) {
    (new AppLogger())->logApiRequest($endpoint, ['method' => $request['method']]);
    if (!(new RateLimiter($db))->checkAndRecord('api', 60, 60)) {
      return [429, ['message' => 'Rate limit exceeded. Please try again later.']];
    }
  }

  $caller = ApiCaller::fromCredentials($db, $request['access_token'], $request['authorization']);
  if ($caller === null) {
    return [401, ['authenticated' => false, 'message' => 'You have not been authenticated']];
  }

  $handler = $handlers[$request['method']] ?? null;
  if ($handler === null) {
    return [405, ['message' => 'Method not allowed. Supported methods: ' . implode(', ', array_keys($handlers))]];
  }

  if (!array_key_exists('body', $request)) {
    if (api_request_content_length_exceeds_limit($request['content_length'] ?? null)) {
      return [413, ['message' => 'Request body too large.']];
    }
    $source = array_key_exists('body_stream', $request) ? $request['body_stream'] : 'php://input';
    $raw = api_request_read_body($source);
    if ($raw === null) {
      return [413, ['message' => 'Request body too large.']];
    }
    $request['body'] = json_decode($raw);
  }

  return $handler($caller, $request);
}

/** True when a declared Content-Length exceeds API_MAX_REQUEST_BODY_BYTES. */
function api_request_content_length_exceeds_limit($contentLength): bool {
  if (!is_int($contentLength) && !is_string($contentLength)) {
    return false;
  }
  $trimmed = trim((string) $contentLength);
  if ($trimmed === '' || !ctype_digit($trimmed)) {
    return false;
  }
  return (float) $trimmed > API_MAX_REQUEST_BODY_BYTES;
}

/**
 * Reads at most API_MAX_REQUEST_BODY_BYTES + 1 bytes from a stream URI or
 * open stream resource, returning null when the body exceeds the limit.
 */
function api_request_read_body($source): ?string {
  $opened = is_string($source);
  $stream = $opened ? fopen($source, 'rb') : (is_resource($source) ? $source : null);
  if (!is_resource($stream)) {
    return '';
  }
  try {
    $raw = stream_get_contents($stream, API_MAX_REQUEST_BODY_BYTES + 1);
  } finally {
    if ($opened) {
      fclose($stream);
    }
  }
  if ($raw === false) {
    return '';
  }
  return strlen($raw) > API_MAX_REQUEST_BODY_BYTES ? null : $raw;
}

/**
 * The request PHP's globals describe, in answer_api_request()'s shape. The
 * only place that reads the request's credentials; body reading and decoding
 * stay deferred to answer_api_request(). Sec-Fetch-Site is
 * sec_fetch_site_header().
 */
function api_request_from_globals(): array {
  $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if ($authorization === '' && function_exists('apache_request_headers')) {
    $authorization = apache_request_headers()['Authorization'] ?? '';
  }
  // A cookie sent as an array is present but can't be a valid token.
  $access_token = $_COOKIE['access_token'] ?? null;
  if ($access_token !== null && !is_string($access_token)) {
    $access_token = '';
  }
  return [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'query' => $_GET,
    'content_length' => api_request_content_length(),
    'body_stream' => 'php://input',
    'access_token' => $access_token,
    'authorization' => $authorization === '' ? null : $authorization,
    'sec_fetch_site' => sec_fetch_site_header(),
  ];
}

/** The Content-Length header PHP's globals carry, or null when absent or blank. */
function api_request_content_length(): ?string {
  foreach (['CONTENT_LENGTH', 'HTTP_CONTENT_LENGTH'] as $key) {
    $value = $_SERVER[$key] ?? null;
    if (is_int($value)) {
      return (string) $value;
    }
    if (is_string($value)) {
      $trimmed = trim($value);
      if ($trimmed !== '') {
        return $trimmed;
      }
    }
  }
  return null;
}

/** Sends answer_api_request()'s [status, body] as the JSON response. */
function emit_api_response(array $answer): void {
  [$status, $body] = $answer;
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($body);
}

?>
