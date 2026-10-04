<?php

require_once __DIR__ . '/app_logger.php';
require_once __DIR__ . '/kept_status.php';
require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/Items.php';

/**
 * POST /artifact-kept.php: sets kept on the body's id, one of the owner's
 * Items, to the body's is_kept, which must be exactly 0 or 1 (true and false
 * count). Agent keys may (ADR-0002); the master key, which has no Items of
 * its own, gets a 403.
 *
 * Returns [status, response fields].
 */
function set_kept_over_api(mysqli $db, ApiCaller $caller, $body): array {
  $owner = $caller->owner();
  if ($owner === null) {
    return [403, ['message' => 'This endpoint requires a user-scoped credential.']];
  }

  if (!is_object($body) || !isset($body->id) || !is_numeric($body->id)) {
    return [400, ['message' => 'Missing or invalid required field: id']];
  }
  // Strict 0/1: anything else (including truthy strings) is rejected rather
  // than silently coerced, so a malformed call can never flip kept by accident.
  $raw_kept = $body->is_kept ?? null;
  if (!in_array($raw_kept, [0, 1, '0', '1', true, false], true)) {
    return [400, ['message' => 'Missing or invalid required field: is_kept (0 or 1)']];
  }
  $kept = (int) $raw_kept === 1;

  $id = (int) $body->id;
  $items = new Items($db, $owner);
  if ($items->find($id) === null) {
    return [404, ['message' => 'Item not found.']];
  }

  $items->setKept($id, $kept);
  $row = $items->find($id);
  (new AppLogger())->logDataChange('update', 'artifact-kept', $id, ['is_kept' => (int) $kept]);

  return [200, [
    'ok' => true,
    'artifact_id' => $id,
    'artifact_name' => $row['Title'],
    'message' => $row['Title'] . ($kept ? ' is now kept.' : ' is no longer kept.'),
    'is_kept' => artifact_is_kept($row) ? 1 : 0,
    'is_in_secondary_collection' => artifact_is_in_secondary_collection($row) ? 1 : 0,
  ]];
}

?>
