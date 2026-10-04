<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/Types.php';

/**
 * GET /types.php: the key user's types, with the ids an agent needs to
 * filter items with type_id. The master key, which has no types of its own,
 * gets a 400.
 *
 * Returns [status, response fields].
 */
function list_types_over_api(mysqli $db, ApiCaller $caller): array {
  $owner = $caller->owner();
  if ($owner === null) {
    return [400, ['message' => 'types.php requires a user-scoped key.']];
  }

  return [200, ['types' => array_map(fn (array $type) => [
    'id' => $type['id'],
    'type' => $type['name'],
  ], (new Types($db, $owner))->all())]];
}

?>
