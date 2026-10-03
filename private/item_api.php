<?php

require_once __DIR__ . '/item_tags.php';
require_once __DIR__ . '/classes/Items.php';

/**
 * The HTTP API item endpoint's writes: POST creates an Item, PUT patches
 * the Item the body's id names. Both go through the Items module, so an
 * Item written over HTTP follows the Create Item page's rules.
 *
 * The owner is the session's user. The master key has no user of its own,
 * so its body must name the owner as user_id. Agent keys are refused
 * before this runs (ADR-0002).
 *
 * $body is the decoded JSON object. Returns [status, response fields]; on
 * success the response's artifact is the found Item with its tags.
 */
function write_item_over_api(mysqli $db, object $authentication, string $method, $body): array {
  if (!is_object($body)) {
    return [400, ['message' => 'Invalid or missing JSON request body.']];
  }
  $input = item_api_input($body);

  $owner = isset($authentication->user_id)
    ? (int) $authentication->user_id
    : filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if (!$owner) {
    return [400, ['message' => 'Missing or invalid required field: user_id']];
  }

  $id = null;
  if ($method === 'PUT') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
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
    return [422, ['message' => $method === 'PUT' ? 'Failed to update item.' : 'Failed to create item.', 'errors' => $invalid->errors]];
  } catch (OutOfBoundsException $not_found) {
    return [404, ['message' => 'Item not found.']];
  }

  return [$method === 'PUT' ? 200 : 201, [
    'message' => $method === 'PUT' ? 'Item updated successfully.' : 'Item created successfully.',
    'artifact' => with_item_tags($db, [$items->find($id)], $owner)[0],
  ]];
}

/** The body's fields as module input, with JSON booleans as the 1/0 flags the module stores. */
function item_api_input(object $body): array {
  return array_map(fn ($value) => is_bool($value) ? (int) $value : $value, get_object_vars($body));
}

?>
