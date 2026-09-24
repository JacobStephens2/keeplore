<?php

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
 * - list_collection_items(): runs the request against one user's rows.
 */

const COLLECTION_LIST_FLAG_FILTERS = [
  'kept' => 'games.is_kept',
  'physical' => 'games.is_physical',
  'digital' => 'games.is_digital',
  'secondary_collection' => 'games.is_in_secondary_collection',
];

const COLLECTION_LIST_FIELDS = ['basic', 'collection'];

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

  foreach (array_keys(COLLECTION_LIST_FLAG_FILTERS) as $flag) {
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
  foreach (array_keys(COLLECTION_LIST_FLAG_FILTERS) as $flag) {
    if ($request[$flag] !== null) {
      return true;
    }
  }
  return $request['query'] !== '' || $request['tag'] !== '' || $request['type_ids'] !== []
    || $request['fields'] !== 'basic' || $request['include_uses_summary'];
}

/**
 * List one user's items for a parsed request. Offset mode orders by Title;
 * cursor mode orders by id and resumes after the cursor id. Every row
 * carries its tags. Returns items, has_more, and next_cursor (cursor mode).
 */
function list_collection_items($conn, $user_id, array $request) {
  $user_id = (int) $user_id;

  $columns = [
    'games.id', 'games.Title', 'COALESCE(types.objectType, games.type) AS type', 'games.type_id',
    'games.is_kept', 'games.is_physical', 'games.is_digital', 'games.is_in_secondary_collection',
  ];
  if ($request['fields'] === 'collection') {
    array_push(
      $columns,
      'games.MnP', 'games.MxP', 'games.SS', 'games.MnT', 'games.MxT', 'games.Wt', 'games.Yr', 'games.Acq'
    );
  }

  $joins = ' LEFT JOIN types ON types.id = games.type_id';
  $types = '';
  $params = [];
  if ($request['include_uses_summary']) {
    $columns[] = 'COALESCE(use_summary.plays, 0) AS plays';
    $columns[] = 'use_summary.last_use';
    $joins .= ' LEFT JOIN (
        SELECT artifact_id, COUNT(*) AS plays, MAX(use_date) AS last_use
        FROM uses WHERE user_id = ? GROUP BY artifact_id
      ) AS use_summary ON use_summary.artifact_id = games.id';
    $types .= 'i';
    $params[] = $user_id;
  }

  $where = ' WHERE games.user_id = ?';
  $types .= 'i';
  $params[] = $user_id;

  foreach (COLLECTION_LIST_FLAG_FILTERS as $flag => $column) {
    if ($request[$flag] !== null) {
      $where .= ' AND ' . artifact_flag_sql($column, $request[$flag]);
    }
  }

  if ($request['type_ids'] !== []) {
    $where .= ' AND games.type_id IN (' . implode(',', array_fill(0, count($request['type_ids']), '?')) . ')';
    $types .= str_repeat('i', count($request['type_ids']));
    array_push($params, ...$request['type_ids']);
  }

  if ($request['query'] !== '') {
    $where .= ' AND games.Title LIKE ?';
    $types .= 's';
    $params[] = '%' . $request['query'] . '%';
  }

  $tag_filter = item_tag_user_filter($request['tag'], $user_id);
  $where .= $tag_filter['sql'];
  $types .= $tag_filter['types'];
  array_push($params, ...$tag_filter['params']);

  $per_page = $request['per_page'];
  if ($request['use_cursor']) {
    if ($request['cursor'] !== null) {
      $where .= ' AND games.id > ?';
      $types .= 'i';
      $params[] = $request['cursor'];
    }
    $tail = ' ORDER BY games.id ASC LIMIT ?';
    $types .= 'i';
    $params[] = $per_page + 1;
  } else {
    $tail = ' ORDER BY games.Title ASC, games.id ASC LIMIT ? OFFSET ?';
    $types .= 'ii';
    $params[] = $per_page + 1;
    $params[] = ($request['page'] - 1) * $per_page;
  }

  $stmt = mysqli_prepare($conn, 'SELECT ' . implode(', ', $columns) . ' FROM games' . $joins . $where . $tail);
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $items = [];
  while ($row = mysqli_fetch_assoc($result)) {
    if (isset($row['plays'])) {
      $row['plays'] = (int) $row['plays'];
    }
    $items[] = $row;
  }
  mysqli_stmt_close($stmt);

  $has_more = count($items) > $per_page;
  if ($has_more) {
    array_pop($items);
  }
  $next_cursor = null;
  if ($request['use_cursor'] && $has_more) {
    $next_cursor = (int) end($items)['id'];
  }

  return [
    'items' => with_item_tags($conn, $items, $user_id),
    'has_more' => $has_more,
    'next_cursor' => $next_cursor,
  ];
}

?>
