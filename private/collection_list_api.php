<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/collection_list.php';

/**
 * POST (or GET) /artifacts.php: the owner's collection list (issue #58),
 * shaped by the body as parse_collection_list_request() reads it. The
 * master key names the owner in the body's userid, or naming none gets the
 * legacy all-users id and Title listing, which takes no filters. A session
 * or an agent key ignores userid.
 *
 * Returns [status, response fields]: per_page, artifacts and, for a
 * collection, has_more; then next_cursor in cursor mode, or page.
 */
function list_collection_over_api(mysqli $db, ApiCaller $caller, $body): array {
  $requested_user_id = is_object($body) ? ($body->userid ?? '') : '';
  $owner = $caller->owner($requested_user_id);
  if ($owner === null && $requested_user_id !== '') {
    return [400, ['message' => 'Invalid field: userid must name an existing user.']];
  }

  $request = parse_collection_list_request($body);
  if ($request['errors'] !== []) {
    return [400, ['message' => 'Invalid list request.', 'errors' => $request['errors']]];
  }
  if ($owner === null && collection_list_has_filters($request)) {
    return [400, ['message' => 'Filters and extra fields need a collection: send userid.']];
  }
  $fields = ['per_page' => $request['per_page']];

  if ($owner !== null) {
    $result = list_collection_items($db, $owner, $request);
    $fields += ['artifacts' => $result['items'], 'has_more' => $result['has_more']];
    $fields += $request['use_cursor'] ? ['next_cursor' => $result['next_cursor']] : ['page' => $request['page']];
  } else {
    $fields += list_all_accounts_titles($db, $request);
  }
  return [200, $fields];
}

/**
 * The legacy all-accounts listing: every account's Items as id and Title,
 * paged as $request, a parsed list request, asks. Cursor mode orders by
 * id and resumes after the cursor; otherwise the page is ordered by
 * Title, then id.
 *
 * Returns the response fields: artifacts, then next_cursor and has_more
 * in cursor mode, or page.
 */
function list_all_accounts_titles(mysqli $db, array $request): array {
  $per_page = $request['per_page'];
  if ($request['use_cursor']) {
    $after = $request['cursor'] ?? 0;
    $limit = $per_page + 1;
    $stmt = $db->prepare('SELECT id, Title FROM games WHERE id > ? ORDER BY id LIMIT ?');
    $stmt->bind_param('ii', $after, $limit);
  } else {
    $offset = ($request['page'] - 1) * $per_page;
    $stmt = $db->prepare('SELECT id, Title FROM games ORDER BY Title, id LIMIT ? OFFSET ?');
    $stmt->bind_param('ii', $per_page, $offset);
  }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  if (!$request['use_cursor']) {
    return ['artifacts' => $rows, 'page' => $request['page']];
  }
  $has_more = count($rows) > $per_page;
  if ($has_more) {
    array_pop($rows);
  }
  return ['artifacts' => $rows, 'next_cursor' => $has_more ? end($rows)['id'] : null, 'has_more' => $has_more];
}

?>
