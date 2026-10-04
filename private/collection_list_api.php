<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/DatabaseObject.class.php';
require_once __DIR__ . '/classes/Artifact.class.php';
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
  } elseif ($request['use_cursor']) {
    $result = Artifact::list_artifacts_paginated($request['per_page'], $request['cursor']);
    $fields += ['artifacts' => $result['data'], 'next_cursor' => $result['next_cursor'], 'has_more' => $result['has_more']];
  } else {
    $fields += ['artifacts' => Artifact::list_artifacts($request['page'], $request['per_page']), 'page' => $request['page']];
  }
  return [200, $fields];
}

?>
