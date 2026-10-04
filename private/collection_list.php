<?php

require_once __DIR__ . '/classes/Items.php';

/**
 * Collection list seam behind POST /artifacts.php (issue #58).
 *
 * One user's items, narrowed by kept/format/type/tag/title filters, with
 * optional collection columns and a per-item play summary, so an agent can
 * answer "which games do I keep, for how many players, and when did we last
 * play them" in a handful of requests.
 *
 * - parse_collection_list_request(): pure; turns a JSON body into a
 *   normalized request and a list of validation errors.
 * - list_collection_items(): runs the request against one user's Items,
 *   read through Items::list, and pages the result.
 */

/** The request's flag filters, each named as the Items list filter it maps to. */
const COLLECTION_LIST_FLAGS = ['kept', 'physical', 'digital', 'secondary_collection'];

const COLLECTION_LIST_FIELDS = ['basic', 'collection'];

/** The columns each row carries for each fields value, in output order. */
const COLLECTION_LIST_COLUMNS = [
  'basic' => ['id', 'Title', 'type', 'type_id', 'is_kept', 'is_physical', 'is_digital', 'is_in_secondary_collection'],
  'collection' => ['MnP', 'MxP', 'SS', 'MnT', 'MxT', 'Wt', 'Yr', 'Acq'],
];

const COLLECTION_LIST_INCLUDES = ['uses_summary'];

function parse_collection_list_flag($value) {
  if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes') {
    return true;
  }
  if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === 'no') {
    return false;
  }
  return null;
}

function parse_collection_list_request($body) {
  $body = is_object($body) ? get_object_vars($body) : (is_array($body) ? $body : []);
  $errors = [];

  $request = [
    'query' => '',
    'tag' => '',
    'type_ids' => [],
    'fields' => 'basic',
    'include_uses_summary' => false,
    'page' => max(1, (int) ($body['page'] ?? 1)),
    'per_page' => max(1, min(200, (int) ($body['per_page'] ?? 50))),
    'use_cursor' => array_key_exists('cursor', $body),
    'cursor' => isset($body['cursor']) ? (int) $body['cursor'] : null,
  ];

  foreach (['query', 'tag'] as $text_field) {
    if (!isset($body[$text_field])) {
      continue;
    }
    if (is_scalar($body[$text_field])) {
      $request[$text_field] = trim((string) $body[$text_field]);
    } else {
      $errors[] = $text_field . ' must be a string.';
    }
  }

  foreach (COLLECTION_LIST_FLAGS as $flag) {
    $request[$flag] = null;
    if (!isset($body[$flag])) {
      continue;
    }
    $request[$flag] = parse_collection_list_flag($body[$flag]);
    if ($request[$flag] === null) {
      $errors[] = $flag . ' must be true or false.';
    }
  }

  if (isset($body['type_id'])) {
    $type_ids = is_array($body['type_id']) ? $body['type_id'] : [$body['type_id']];
    foreach ($type_ids as $type_id) {
      if (!is_int($type_id) && !(is_string($type_id) && ctype_digit($type_id))) {
        $errors[] = 'type_id must be an integer or a list of integers (see GET /types.php).';
        break;
      }
      $request['type_ids'][] = (int) $type_id;
    }
    $request['type_ids'] = array_values(array_unique($request['type_ids']));
  }

  if (isset($body['fields'])) {
    if (in_array($body['fields'], COLLECTION_LIST_FIELDS, true)) {
      $request['fields'] = $body['fields'];
    } else {
      $errors[] = 'fields must be one of: ' . implode(', ', COLLECTION_LIST_FIELDS) . '.';
    }
  }

  if (isset($body['include'])) {
    $includes = is_array($body['include']) ? $body['include'] : [$body['include']];
    foreach ($includes as $include) {
      if (!in_array($include, COLLECTION_LIST_INCLUDES, true)) {
        $label = is_scalar($include) ? (string) $include : gettype($include);
        $errors[] = 'Unknown include "' . $label . '"; supported: ' . implode(', ', COLLECTION_LIST_INCLUDES) . '.';
        continue;
      }
      $request['include_uses_summary'] = true;
    }
  }

  $request['errors'] = $errors;
  return $request;
}

/**
 * True when the request narrows or widens rows beyond a plain id/Title
 * page, which only a user-scoped list can honour.
 */
function collection_list_has_filters(array $request) {
  foreach (COLLECTION_LIST_FLAGS as $flag) {
    if ($request[$flag] !== null) {
      return true;
    }
  }
  return $request['query'] !== '' || $request['tag'] !== '' || $request['type_ids'] !== []
    || $request['fields'] !== 'basic' || $request['include_uses_summary'];
}

/**
 * List one user's items for a parsed request, read through Items::list.
 * Offset mode slices the Title-ordered list; cursor mode orders by id and
 * resumes after the cursor id. Every row carries its tags; uses_summary
 * adds plays (recorded Uses) and last_use (the Items last-use rule).
 * Returns items, has_more, and next_cursor (cursor mode).
 */
function list_collection_items($conn, $user_id, array $request) {
  $filters = [
    'title' => $request['query'],
    'tag' => $request['tag'],
    'type_ids' => $request['type_ids'] === [] ? null : $request['type_ids'],
  ];
  foreach (COLLECTION_LIST_FLAGS as $flag) {
    $filters[$flag] = $request[$flag];
  }
  $items = (new Items($conn, (int) $user_id))->list($filters);

  $per_page = $request['per_page'];
  if ($request['use_cursor']) {
    usort($items, fn (array $a, array $b) => $a['id'] <=> $b['id']);
    if ($request['cursor'] !== null) {
      $items = array_filter($items, fn (array $item) => $item['id'] > $request['cursor']);
    }
    $page = array_slice($items, 0, $per_page + 1);
  } else {
    $page = array_slice($items, ($request['page'] - 1) * $per_page, $per_page + 1);
  }

  $has_more = count($page) > $per_page;
  if ($has_more) {
    array_pop($page);
  }
  $next_cursor = null;
  if ($request['use_cursor'] && $has_more) {
    $next_cursor = (int) end($page)['id'];
  }

  return [
    'items' => array_map(fn (array $item) => collection_list_row($item, $request), $page),
    'has_more' => $has_more,
    'next_cursor' => $next_cursor,
  ];
}

/**
 * One listed Item as the request's fields and includes shape it. Column
 * names are matched case-insensitively, since older schemas spell them in
 * lower case.
 */
function collection_list_row(array $item, array $request) {
  $columns = COLLECTION_LIST_COLUMNS['basic'];
  if ($request['fields'] === 'collection') {
    array_push($columns, ...COLLECTION_LIST_COLUMNS['collection']);
  }
  $by_lower_name = array_change_key_case($item);
  $row = [];
  foreach ($columns as $column) {
    $row[$column] = $by_lower_name[strtolower($column)] ?? null;
  }
  $row['type'] = $item['type_name'] ?? $row['type'];
  if ($request['include_uses_summary']) {
    $row['plays'] = $item['use_count'];
    $row['last_use'] = $item['last_use'];
  }
  $row['tags'] = $item['tags'];
  return $row;
}

?>
