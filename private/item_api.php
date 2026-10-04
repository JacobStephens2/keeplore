<?php

require_once __DIR__ . '/app_logger.php';
require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/DatabaseObject.class.php';
require_once __DIR__ . '/classes/Artifact.class.php';
require_once __DIR__ . '/classes/Items.php';

/** What each item write over HTTP answers with. */
const ITEM_API_WRITES = [
  'POST' => ['status' => 201, 'succeeded' => 'Item created successfully.', 'failed' => 'Failed to create item.'],
  'PUT' => ['status' => 200, 'succeeded' => 'Item updated successfully.', 'failed' => 'Failed to update item.'],
];

/** The log action each item write over HTTP records. */
const ITEM_API_LOG_ACTIONS = ['POST' => 'create', 'PUT' => 'update', 'DELETE' => 'delete'];

/**
 * GET /artifact.php: the Item the query's id names, from the caller's own
 * Items, as Items::find returns it. The master key names the owner in the
 * query's user_id, or naming none reads any Item by id alone, with an
 * empty tag list (the legacy lookup).
 *
 * Returns [status, response fields]; on success the fields' artifact is
 * the Item.
 */
function read_item_over_api(mysqli $db, ApiCaller $caller, array $query): array {
  $id = item_api_positive_int($query['id'] ?? null);
  if ($id === null) {
    return [400, ['message' => 'Missing or invalid required parameter: id']];
  }
  $owner = $caller->owner($query['user_id'] ?? null);
  if ($owner === null && isset($query['user_id'])) {
    return [400, ['message' => 'Missing or invalid required parameter: user_id']];
  }

  $artifact = $owner === null ? Artifact::find_by_id($id) : (new Items($db, $owner))->find($id);
  if (!$artifact) {
    return [404, ['message' => 'Item not found.']];
  }
  return [200, ['artifact' => $artifact]];
}

/**
 * The HTTP API item endpoint's writes: POST creates an Item, PUT patches
 * the Item the body's id names. Both go through the Items module, so an
 * Item written over HTTP follows the Create Item page's rules. Agent keys
 * are refused (ADR-0002).
 *
 * $body is the decoded JSON object; the owner is the caller's, with the
 * body's user_id as the master key's choice. Returns [status, response
 * fields]; on success the fields' artifact is the found Item with its
 * tags, and the write is logged.
 */
function write_item_over_api(mysqli $db, ApiCaller $caller, string $method, $body): array {
  $write = ITEM_API_WRITES[$method];
  $refusal = $caller->agentKeyRefusal();
  if ($refusal !== null) {
    return $refusal;
  }
  if (!is_object($body)) {
    return [400, ['message' => 'Invalid or missing JSON request body.']];
  }
  $input = item_api_input($body);

  $owner = $caller->owner($input['user_id'] ?? null);
  if ($owner === null) {
    return [400, ['message' => 'Missing or invalid required field: user_id']];
  }

  $id = null;
  if ($method === 'PUT') {
    $id = item_api_positive_int($input['id'] ?? null);
    if ($id === null) {
      return [400, ['message' => 'Missing or invalid required field: id']];
    }
  }

  $items = new Items($db, $owner);
  try {
    if ($id === null) {
      $id = $items->create($input);
    } else {
      $items->update($id, $input);
    }
  } catch (ItemInvalid $invalid) {
    return [422, ['message' => $write['failed'], 'errors' => $invalid->errors]];
  } catch (OutOfBoundsException $not_found) {
    return [404, ['message' => 'Item not found.']];
  }

  $item = $items->find($id);
  log_item_write_over_api($method, $item);
  return [$write['status'], ['message' => $write['succeeded'], 'artifact' => $item]];
}

/**
 * The HTTP API item endpoint's DELETE: removes the Item the query's id
 * names, with everything that points at it, through the Items module.
 * The master key names the owner in the query's user_id. Agent keys are
 * refused (ADR-0002). Returns [status, response fields]; on success the
 * fields' artifact is the deleted Item, and the write is logged.
 */
function delete_item_over_api(mysqli $db, ApiCaller $caller, array $query): array {
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

  $items = new Items($db, $owner);
  $item = $items->find($id);
  try {
    $items->delete($id);
  } catch (OutOfBoundsException $not_found) {
    return [404, ['message' => 'Item not found.']];
  }
  log_item_write_over_api('DELETE', $item);
  return [200, ['message' => 'Item deleted successfully.', 'artifact' => $item]];
}

/** Logs an item write over HTTP under $method's action. */
function log_item_write_over_api(string $method, array $item): void {
  (new AppLogger())->logDataChange(ITEM_API_LOG_ACTIONS[$method], 'artifact', $item['id'], ['title' => $item['Title']]);
}

/** A request's id as a positive whole number, or null. */
function item_api_positive_int($value): ?int {
  $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  return $int === false ? null : $int;
}

/** The body's fields as module input, with JSON booleans as the 1/0 flags the module stores. */
function item_api_input(object $body): array {
  return array_map(fn ($value) => is_bool($value) ? (int) $value : $value, get_object_vars($body));
}

?>
