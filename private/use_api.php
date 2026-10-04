<?php

require_once __DIR__ . '/item_api.php';
require_once __DIR__ . '/app_logger.php';
require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/Uses.php';

/** The log action each use write over HTTP records. */
const USE_API_LOG_ACTIONS = ['POST' => 'create', 'DELETE' => 'delete'];

/**
 * GET /uses.php: the owner's uses, newest first, through the Uses module.
 * The query's artifact_id and player_id keep one item's uses or the uses a
 * person took part in. The master key names the owner in the query's
 * user_id, or naming none reads the uses of the artifact_id's owner.
 *
 * Returns [status, response fields] with the legacy field names: note is
 * the Setting, notesTwo the notes, players the person ids.
 */
function list_uses_over_api(mysqli $db, ApiCaller $caller, array $query): array {
  $item_id = item_api_positive_int($query['artifact_id'] ?? null);
  $person_id = item_api_positive_int($query['player_id'] ?? null);
  $owner = $caller->owner($query['user_id'] ?? null);
  if ($owner === null && !isset($query['user_id'])) {
    $owner = use_api_item_owner($db, $item_id);
  }
  if ($owner === null) {
    return [400, ['message' => 'user_id or artifact_id parameter is required for API key authentication.']];
  }

  return [200, ['uses' => array_map('use_api_fields', (new Uses($db, $owner))->all(['item_id' => $item_id, 'person_id' => $person_id]))]];
}

/**
 * A use as the HTTP interface names its fields: note is the Setting,
 * notesTwo the notes, players the person ids, participants the people.
 */
function use_api_fields(array $use): array {
  return [
    'id' => $use['id'],
    'artifact_id' => $use['item_id'],
    'artifact_title' => $use['item_title'],
    'use_date' => $use['use_date'],
    'note' => $use['setting'],
    'notesTwo' => $use['notes'],
    'players' => array_column($use['people'], 'id'),
    'participants' => array_map(fn (array $person) => [
      'id' => $person['id'],
      'FirstName' => $person['first_name'],
      'LastName' => $person['last_name'],
    ], $use['people']),
  ];
}

/**
 * POST /uses.php: records one use of the body's artifact_id on its
 * use_date, with note as the Setting and notesTwo as notes, through the
 * Uses module. The master key names the owner in the body's user_id. Agent
 * keys are refused (ADR-0002), and every refusal from the module is a 400.
 *
 * Returns [status, response fields]; on success the fields' use is the
 * recorded use, and the write is logged.
 */
function record_use_over_api(mysqli $db, ApiCaller $caller, $body): array {
  $refusal = $caller->agentKeyRefusal();
  if ($refusal !== null) {
    return $refusal;
  }
  if (!is_object($body)) {
    return [400, ['message' => 'Invalid or missing JSON request body.']];
  }
  $owner = $caller->owner($body->user_id ?? null);
  if ($owner === null) {
    return [400, ['message' => 'Missing or invalid required field: user_id']];
  }

  $uses = new Uses($db, $owner);
  try {
    [$id] = $uses->record([
      'item_id' => $body->artifact_id ?? null,
      'use_date' => $body->use_date ?? null,
      'setting' => $body->note ?? '',
      'notes' => $body->notesTwo ?? '',
    ]);
  } catch (InvalidArgumentException $invalid) {
    return [400, ['message' => $invalid->getMessage()]];
  }

  $use = use_api_fields($uses->find($id));
  log_use_write_over_api('POST', $use);
  return [201, ['message' => 'Use recorded successfully.', 'use' => [
    'id' => $use['id'],
    'artifact_id' => $use['artifact_id'],
    'use_date' => $use['use_date'],
    'user_id' => $owner,
    'note' => $use['note'],
    'notesTwo' => $use['notesTwo'],
  ]]];
}

/**
 * DELETE /uses.php: removes the use the query's id names, with its people
 * links, through the Uses module. The master key names the owner in the
 * query's user_id. Agent keys are refused (ADR-0002), and a use the owner
 * doesn't have is a 404.
 *
 * Returns [status, response fields]; on success the fields' use is the
 * deleted use, and the write is logged.
 */
function delete_use_over_api(mysqli $db, ApiCaller $caller, array $query): array {
  $refusal = $caller->agentKeyRefusal();
  if ($refusal !== null) {
    return $refusal;
  }
  $id = item_api_positive_int($query['id'] ?? null);
  if ($id === null) {
    return [400, ['message' => 'Missing or invalid required parameter: id']];
  }
  $owner = $caller->owner($query['user_id'] ?? null);
  if ($owner === null) {
    return [400, ['message' => 'Missing or invalid required parameter: user_id']];
  }

  $uses = new Uses($db, $owner);
  $use = $uses->find($id);
  try {
    $uses->delete($id);
  } catch (OutOfBoundsException $not_found) {
    return [404, ['message' => 'Use record not found.']];
  }
  $deleted = use_api_fields($use);
  log_use_write_over_api('DELETE', $deleted);
  return [200, ['message' => 'Use record deleted successfully.', 'use' => $deleted]];
}

/** Logs a use write over HTTP under $method's action. */
function log_use_write_over_api(string $method, array $use): void {
  (new AppLogger())->logDataChange(USE_API_LOG_ACTIONS[$method], 'use', $use['id'], [
    'artifact_id' => $use['artifact_id'],
    'use_date' => $use['use_date'],
  ]);
}

/** The owner of the item $item_id names, or null when there is no such item. */
function use_api_item_owner(mysqli $db, ?int $item_id): ?int {
  if ($item_id === null) {
    return null;
  }
  $stmt = $db->prepare('SELECT user_id FROM games WHERE id = ?');
  $stmt->bind_param('i', $item_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row === null ? null : (int) $row['user_id'];
}

?>
