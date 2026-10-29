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

/** Where each Quick item action is posted, as an app path. */
const QUICK_ITEM_ACTION_PATH = [
  'snooze' => '/artifacts/snooze.php',
  'kept' => '/artifacts/set-tracked.php',
  'get-rid-of' => '/artifacts/mark-get-rid-of.php',
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
 * $request is quick_item_action_request_from_globals()'s shape. The Item
 * changes only when quick_item_action_may_change() is true: a POST whose
 * CSRF token already matches the session. Anything else, including a GET,
 * leaves the Item as it is. With no artifact_id the answer is 400; a
 * missing or another owner's Item is 404, goes back to the Items page and
 * changes nothing. Otherwise the action changes its own field of the Item.
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

  if (!quick_item_action_may_change()) {
    $status = is_post_request() ? 403 : 405;
    $message = is_post_request()
      ? 'Invalid CSRF token.'
      : 'Quick item actions accept a POST that has passed the CSRF check.';
    return ['status' => $status, 'body' => ['ok' => false, 'message' => $message], 'path' => $path];
  }

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

/**
 * A Quick item action may change the Item only when this request is a POST
 * whose CSRF token already matches the session. validate_csrf_token() is
 * that check: a GET, including a Daily email link, does not pass it.
 */
function quick_item_action_may_change(): bool {
  return is_post_request() && validate_csrf_token();
}

/**
 * What this request should do with one Quick item action.
 *
 * A POST that already passed the CSRF check is answered by
 * answer_quick_item_action(), which changes the Item. Anything else is a
 * page that names the Item and posts the same action with the CSRF token.
 * Opening that page does not change the Item.
 *
 * Returns ['answer' => answer_quick_item_action()'s answer] or
 * ['page' => ['title' => string, 'html' => the page body]].
 * An unknown action throws InvalidArgumentException.
 */
function quick_item_action_response(mysqli $db, int $owner_id, string $action, array $request): array {
  if (!isset(quick_item_actions($db, $owner_id)[$action])) {
    throw new InvalidArgumentException('Unknown quick item action: ' . $action);
  }
  if (quick_item_action_may_change()) {
    return ['answer' => answer_quick_item_action($db, $owner_id, $action, $request)];
  }
  return ['page' => quick_item_action_page($db, $owner_id, $action, $request)];
}

/**
 * The page a Daily email link opens: the question, the Item's name when it
 * is one of the owner's, and a POST form that carries the CSRF token.
 * A missing Item has no form. Nothing here writes the Item.
 *
 * Returns ['title' => string, 'html' => the <main> body].
 */
function quick_item_action_page(mysqli $db, int $owner_id, string $action, array $request): array {
  $actions = quick_item_actions($db, $owner_id);
  if (!isset($actions[$action])) {
    throw new InvalidArgumentException('Unknown quick item action: ' . $action);
  }
  [$default_return_to] = $actions[$action];
  [$title, $question, $submit] = quick_item_action_prompt($action, $request['value']);
  $back = QUICK_ITEM_ACTION_RETURN_TO[$request['return_to'] ?? ''] ?? QUICK_ITEM_ACTION_RETURN_TO[$default_return_to];

  $item = $request['artifact_id'] === null ? null : (new Items($db, $owner_id))->find($request['artifact_id']);

  $html = '<main>'
    . '<a class="back-link" href="' . h(url_for($back)) . '">&laquo; Back</a>'
    . '<div class="object delete">'
    . '<h1>' . h($title) . '</h1>';
  if ($item === null) {
    $html .= '<p>' . h($request['artifact_id'] === null ? 'No item specified.' : 'Item not found.') . '</p>'
      . '</div></main>';
    return ['title' => $title, 'html' => $html];
  }

  $html .= '<p>' . h($question) . '</p>'
    . '<p class="item">' . h($item['Title']) . '</p>'
    . '<form action="' . h(url_for(QUICK_ITEM_ACTION_PATH[$action])) . '" method="post">'
    . csrf_input()
    . '<input type="hidden" name="artifact_id" value="' . h((string) $request['artifact_id']) . '">';
  if ($request['value'] !== null) {
    $html .= '<input type="hidden" name="value" value="' . h((string) $request['value']) . '">';
  }
  if ($request['days'] !== null) {
    $html .= '<input type="hidden" name="days" value="' . h((string) $request['days']) . '">';
  }
  if ($request['return_to'] !== null) {
    $html .= '<input type="hidden" name="return_to" value="' . h($request['return_to']) . '">';
  }
  $html .= '<div id="operations"><input type="submit" name="commit" value="' . h($submit) . '" /></div>'
    . '</form></div></main>';
  return ['title' => $title, 'html' => $html];
}

/**
 * The confirmation copy for one Quick item action: page title, the question,
 * and the submit label. Kept and To get rid of read $value the way the
 * change does: kept with no value stops keeping, and To get rid of with no
 * value marks the Item.
 */
function quick_item_action_prompt(string $action, ?int $value): array {
  if ($action === 'snooze') {
    return ['Snooze', 'Are you sure you want to snooze this item?', 'Snooze'];
  }
  if ($action === 'kept') {
    return $value === 1
      ? ['Keep', 'Are you sure you want to keep this item?', 'Keep']
      : ['Stop keeping', 'Are you sure you want to stop keeping this item?', 'Stop keeping'];
  }
  if ($action === 'get-rid-of') {
    return $value === 0
      ? ['Restore', 'Are you sure you want to restore this item to the collection?', 'Restore']
      : ['To get rid of', 'Are you sure you want to mark this item to get rid of?', 'Mark to get rid of'];
  }
  throw new InvalidArgumentException('Unknown quick item action: ' . $action);
}

/**
 * The three Quick item action pages. A POST that already passed the CSRF
 * check is sent with emit_quick_item_action_answer(). Anything else is the
 * confirmation page, which does not change the Item. $db is the connection
 * footer.php closes.
 */
function present_quick_item_action(mysqli $db, int $owner_id, string $action): void {
  $request = quick_item_action_request_from_globals();
  $response = quick_item_action_response($db, $owner_id, $action, $request);
  if (isset($response['page'])) {
    $page_title = $response['page']['title'];
    include SHARED_PATH . '/header.php';
    echo $response['page']['html'];
    include SHARED_PATH . '/footer.php';
    return;
  }
  emit_quick_item_action_answer($request, $response['answer']);
}

?>
