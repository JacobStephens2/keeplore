<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/classes/Items.php';
require_once __DIR__ . '/classes/Preferences.php';

/**
 * Where each return_to goes back to, as an app path. Every one is the
 * owner's own page, so every Quick item action accepts all of them.
 */
const QUICK_ITEM_ACTION_RETURN_TO = [
  'dashboard' => '/index.php#priority-queue',
  'useby' => '/artifacts/useby.php',
  'index' => '/artifacts/index.php',
  'to-get-rid-of' => '/artifacts/to-get-rid-of.php',
  'new' => '/artifacts/new',
];

/**
 * Each Quick item action: its default return_to, used when the request's is
 * missing or unknown, and what it does to the owner's Item. The change runs
 * with the Items, the Item's id and name, and the request, and returns the
 * success body.
 */
function quick_item_actions(mysqli $db, int $owner_id): array {
  return [
    'snooze' => ['dashboard', function (Items $items, int $id, string $name, array $request) use ($db, $owner_id): array {
      $days = $request['days'];
      if ($days === null || $days < 1) {
        $days = (new Preferences($db, $owner_id))->get()['default_snooze_days'];
      }
      $until = $items->snooze($id, $days);
      return [
        'ok' => true,
        'artifact_id' => $id,
        'artifact_name' => $name,
        'snoozed_until' => $until,
        'message' => $name . ' snoozed until ' . $until . '.',
      ];
    }],
    'kept' => ['useby', function (Items $items, int $id, string $name, array $request): array {
      $value = $request['value'] ?? 0;
      $items->setKept($id, $value === 1);
      return [
        'ok' => true,
        'value' => $value,
        'is_kept' => $value === 1 ? 1 : 0,
        'artifact_id' => $id,
        'artifact_name' => $name,
        'message' => $value === 1 ? $name . ' is now kept.' : $name . ' is no longer kept.',
      ];
    }],
    'get-rid-of' => ['useby', function (Items $items, int $id, string $name, array $request): array {
      $value = $request['value'] ?? 1;
      $items->setToGetRidOf($id, $value === 1);
      return [
        'ok' => true,
        'value' => $value,
        'artifact_id' => $id,
        'artifact_name' => $name,
        'message' => $value === 1 ? $name . ' marked to get rid of.' : $name . ' restored to collection.',
      ];
    }],
  ];
}

/**
 * Answers one Quick item action on one of the owner's Items: snooze, kept
 * (Mark kept or not kept) or get-rid-of (Mark to get rid of or restore).
 * An unknown action throws InvalidArgumentException.
 *
 * $request is quick_item_action_request_from_globals()'s shape. With no
 * artifact_id the answer is 400; a missing or another owner's Item is 404,
 * goes back to the Items page and changes nothing. Otherwise the action
 * changes its own field of the Item.
 *
 * Returns ['status' => int, 'body' => the JSON body, always with ok and
 * message, 'path' => the app path to go back to]. Sends nothing.
 */
function answer_quick_item_action(mysqli $db, int $owner_id, string $action, array $request): array {
  $actions = quick_item_actions($db, $owner_id);
  if (!isset($actions[$action])) {
    throw new InvalidArgumentException('Unknown quick item action: ' . $action);
  }
  [$default_return_to, $change] = $actions[$action];
  $path = QUICK_ITEM_ACTION_RETURN_TO[$request['return_to'] ?? ''] ?? QUICK_ITEM_ACTION_RETURN_TO[$default_return_to];

  if ($request['artifact_id'] === null) {
    return ['status' => 400, 'body' => ['ok' => false, 'message' => 'No item specified.'], 'path' => $path];
  }

  $id = $request['artifact_id'];
  $items = new Items($db, $owner_id);
  $item = $items->find($id);
  if ($item === null) {
    return ['status' => 404, 'body' => ['ok' => false, 'message' => 'Item not found.'], 'path' => QUICK_ITEM_ACTION_RETURN_TO['index']];
  }

  return ['status' => 200, 'body' => $change($items, $id, $item['Title'], $request), 'path' => $path];
}

/**
 * The request PHP's globals describe, in answer_quick_item_action()'s shape:
 * artifact_id, value and days as ints (null when missing), return_to (null
 * when missing) and is_ajax, whether X-Requested-With is XMLHttpRequest.
 */
function quick_item_action_request_from_globals(): array {
  $int_or_null = fn(string $name) => isset($_REQUEST[$name]) ? (int) $_REQUEST[$name] : null;
  $return_to = $_REQUEST['return_to'] ?? null;
  return [
    'artifact_id' => $int_or_null('artifact_id'),
    'value' => $int_or_null('value'),
    'days' => $int_or_null('days'),
    'return_to' => is_string($return_to) ? $return_to : null,
    'is_ajax' => strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest',
  ];
}

/**
 * Sends answer_quick_item_action()'s answer to $request: AJAX gets the
 * status and the JSON body; anything else gets the message as the session
 * message and a redirect to the answer's path.
 */
function emit_quick_item_action_answer(array $request, array $answer): void {
  if ($request['is_ajax']) {
    http_response_code($answer['status']);
    header('Content-Type: application/json');
    echo json_encode($answer['body']);
    return;
  }
  $_SESSION['message'] = h($answer['body']['message']);
  redirect_to(url_for($answer['path']));
}

?>
