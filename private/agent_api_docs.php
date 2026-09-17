<?php

/**
 * Public catalog for the agent HTTP API (ADR 0002). The HTML docs page,
 * JSON docs, and api/index.php discovery payload all read this so the
 * contract stays in one place.
 */

function agent_api_base_url($api_origin = null) {
  $origin = $api_origin;
  if ($origin === null || $origin === '') {
    $origin = defined('API_ORIGIN') ? API_ORIGIN : 'api.keeplore.app';
  }
  return 'https://' . $origin;
}

function agent_api_docs($api_origin = null) {
  $base = agent_api_base_url($api_origin);
  return [
    'title' => 'Keeplore agent API',
    'base_url' => $base,
    'auth' => [
      'scheme' => 'Bearer',
      'header' => 'Authorization: Bearer <agent-key>',
      'issue_path' => '/settings/agent-keys.php',
      'token_prefix' => 'ak_',
      'scope' => 'reads plus the kept toggle',
    ],
    'rate_limit' => [
      'requests' => 60,
      'window_seconds' => 60,
    ],
    'denied_message' => 'Agent keys permit reads plus the kept toggle only.',
    'endpoints' => [
      [
        'id' => 'list-items',
        'method' => 'POST',
        'path' => '/artifacts.php',
        'summary' => 'List or search items in the key\'s collection.',
        'body_fields' => ['page', 'per_page', 'query', 'cursor'],
        'example_body' => '{"page":1,"per_page":50}',
        'get_lists_first_page' => true,
        'notes' => [
          'Agent keys always read their own user; a userid in the body is ignored.',
          'Default page size is 50; maximum is 200.',
          'The list returns id and Title. Fetch one item for kept, secondary collection, physical, digital, and the rest of the record.',
          'GET with no body also lists page 1 for an agent key.',
        ],
      ],
      [
        'id' => 'show-item',
        'method' => 'GET',
        'path' => '/artifact.php',
        'summary' => 'Read one item by id, scoped to the key\'s user.',
        'query_fields' => ['id'],
        'example_query' => '?id=123',
        'discovery_key' => 'GET /artifact.php?id=<id>',
        'notes' => [
          'Kept is is_kept. Secondary collection, physical, and digital are independent flags.',
        ],
      ],
      [
        'id' => 'list-uses',
        'method' => 'GET',
        'path' => '/uses.php',
        'summary' => 'List recorded uses for the key\'s user.',
        'query_fields' => ['artifact_id'],
        'example_query' => '?artifact_id=123',
        'notes' => [
          'Omit artifact_id to list every use. A use is an actual interaction, not an item proposal.',
        ],
      ],
      [
        'id' => 'list-proposals',
        'method' => 'GET',
        'path' => '/proposals.php',
        'summary' => 'Proposal outcome counts per item: explicit declines and chose something else.',
        'query_fields' => ['start', 'end', 'include_other', 'sort', 'direction'],
        'sort_values' => ['item_name', 'explicit_declines', 'chose_something_else'],
        'example_query' => '?start=2026-01-01&end=2026-12-31&sort=explicit_declines&direction=desc',
        'notes' => [
          'start and end are YYYY-MM-DD. Default sort is explicit_declines descending.',
          'By default the report covers kept items and the secondary collection. include_other=1 adds the rest.',
          'Unsuccessful proposals do not count as uses and do not restart time since last use.',
        ],
      ],
      [
        'id' => 'list-types',
        'method' => 'GET',
        'path' => '/types.php',
        'summary' => 'List item types for the key\'s user.',
        'query_fields' => [],
        'notes' => [],
      ],
      [
        'id' => 'upcoming-interactions',
        'method' => 'GET',
        'path' => '/upcoming-interactions.php',
        'summary' => 'Upcoming interact-by dates for kept items.',
        'query_fields' => [],
        'notes' => [
          'Interact-by dates come from recorded uses, not from proposal outcomes.',
        ],
      ],
      [
        'id' => 'set-kept',
        'method' => 'POST',
        'path' => '/artifact-kept.php',
        'summary' => 'Flip whether an item is kept. The only write an agent key may perform.',
        'body_fields' => ['id', 'is_kept'],
        'example_body' => '{"id":123,"is_kept":1}',
        'is_kept_values' => [0, 1],
        'notes' => [
          'is_kept must be 0 or 1. This does not change secondary collection, physical, digital, or to get rid of.',
        ],
      ],
    ],
  ];
}

function agent_api_discovery($api_origin = null) {
  $base = agent_api_base_url($api_origin);
  $docs = agent_api_docs($api_origin);
  $endpoints = [];
  foreach ($docs['endpoints'] as $endpoint) {
    $key = $endpoint['discovery_key'] ?? ($endpoint['method'] . ' ' . $endpoint['path']);
    $endpoints[$key] = $base . $endpoint['path'];
  }
  return [
    'message' => 'Hello from the Keeplore API.',
    'agent_auth' => $docs['auth']['header'] . ' (see ' . $docs['auth']['issue_path'] . ')',
    'endpoints' => $endpoints,
  ];
}

function agent_api_curl_example($endpoint, $base_url) {
  $url = $base_url . $endpoint['path'] . ($endpoint['example_query'] ?? '');
  $auth = '-H "Authorization: Bearer ak_your_secret_here"';
  if ($endpoint['method'] === 'GET') {
    return 'curl -s "' . $url . '" \\' . "\n  " . $auth;
  }
  $body = $endpoint['example_body'] ?? '{}';
  return 'curl -s "' . $url . '" \\' . "\n  " . $auth
    . ' \\' . "\n  -H \"Content-Type: application/json\" \\"
    . "\n  -d '" . $body . "'";
}

?>
