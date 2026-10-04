<?php

require_once __DIR__ . '/rate_limiter.php';
require_once __DIR__ . '/app_logger.php';
require_once __DIR__ . '/classes/ApiCaller.php';

/**
 * Answers one HTTP API request for the endpoint named $endpoint. In order:
 * the API rate limit (429), the credential (401, with authenticated false),
 * the method (405, naming $handlers' methods). Then the handler for the
 * request's method runs with the ApiCaller and the request, and returns
 * [status, response fields].
 *
 * $request is api_request_from_globals()'s shape: method, query, body (the
 * decoded JSON) and authentication (authenticate()'s result). The request
 * is logged under $endpoint. An endpoint that isn't $metered skips the
 * rate limit and the log.
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

  $caller = ApiCaller::from($db, $request['authentication']);
  if ($caller === null) {
    return [401, [
      'authenticated' => false,
      'message' => $request['authentication']->message ?? 'You have not been authenticated',
    ]];
  }

  $handler = $handlers[$request['method']] ?? null;
  if ($handler === null) {
    return [405, ['message' => 'Method not allowed. Supported methods: ' . implode(', ', array_keys($handlers))]];
  }

  return $handler($caller, $request);
}

/** The request PHP's globals describe, in answer_api_request()'s shape. */
function api_request_from_globals(): array {
  return [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'query' => $_GET,
    'body' => json_decode(file_get_contents('php://input')),
    'authentication' => authenticate(),
  ];
}

/** Sends answer_api_request()'s [status, body] as the JSON response. */
function emit_api_response(array $answer): void {
  [$status, $body] = $answer;
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($body);
}

?>
