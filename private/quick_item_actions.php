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
 * Each Quick item action: its default return_to, the path it is posted to,
 * the confirmation copy, and what it does to the owner's Item. The change
 * runs with the Items, the Item's id and name, and the request, and returns
 * the success body. Kept and To get rid of share one value rule: a missing
 * value is when_missing, and the Item is on only when that value is 1.
 */
function quick_item_actions(mysqli $db, int $owner_id): array {
  $snooze = [
    'return_to' => 'dashboard',
    'path' => '/artifacts/snooze.php',
    'prompt' => ['Snooze', 'Are you sure you want to snooze this item?', 'Snooze'],
  ];
  $snooze['change'] = function (Items $items, int $id, string $name, array $request) use ($db, $owner_id): array {
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
  };

  $kept = [
    'return_to' => 'useby',
    'path' => '/artifacts/set-tracked.php',
    'when_missing' => 0,
    'prompts' => [
      'on' => ['Keep', 'Are you sure you want to keep this item?', 'Keep'],
      'off' => ['Stop keeping', 'Are you sure you want to stop keeping this item?', 'Stop keeping'],
    ],
  ];
  $kept['change'] = function (Items $items, int $id, string $name, array $request) use ($kept): array {
    $value = quick_item_action_chosen_value($request, $kept['when_missing']);
    $items->setKept($id, $value === 1);
    return [
      'ok' => true,
      'value' => $value,
      'is_kept' => $value === 1 ? 1 : 0,
      'artifact_id' => $id,
      'artifact_name' => $name,
      'message' => $value === 1 ? $name . ' is now kept.' : $name . ' is no longer kept.',
    ];
  };

  $rid = [
    'return_to' => 'useby',
    'path' => '/artifacts/mark-get-rid-of.php',
    'when_missing' => 1,
    'prompts' => [
      'on' => ['To get rid of', 'Are you sure you want to mark this item to get rid of?', 'Mark to get rid of'],
      'off' => ['Restore', 'Are you sure you want to restore this item to the collection?', 'Restore'],
    ],
  ];
  $rid['change'] = function (Items $items, int $id, string $name, array $request) use ($rid): array {
    $value = quick_item_action_chosen_value($request, $rid['when_missing']);
    $items->setToGetRidOf($id, $value === 1);
    return [
      'ok' => true,
      'value' => $value,
      'artifact_id' => $id,
      'artifact_name' => $name,
      'message' => $value === 1 ? $name . ' marked to get rid of.' : $name . ' restored to collection.',
    ];
  };

  return ['snooze' => $snooze, 'kept' => $kept, 'get-rid-of' => $rid];
}

/** The value a Kept or To get rid of action uses. Missing means $when_missing. */
function quick_item_action_chosen_value(array $request, int $when_missing): int {
  return $request['value'] ?? $when_missing;
}

/**
 * One Quick item action. An unknown action throws InvalidArgumentException.
 */
function quick_item_action_definition(mysqli $db, int $owner_id, string $action): array {
  $actions = quick_item_actions($db, $owner_id);
  if (!isset($actions[$action])) {
    throw new InvalidArgumentException('Unknown quick item action: ' . $action);
  }
  return $actions[$action];
}

/** The app path this request goes back to. An unknown return_to uses the action's default. */
function quick_item_action_return_path(array $definition, array $request): string {
  return QUICK_ITEM_ACTION_RETURN_TO[$request['return_to'] ?? ''] ?? QUICK_ITEM_ACTION_RETURN_TO[$definition['return_to']];
}

/** Page title, the question, and the submit label. */
function quick_item_action_prompt(array $definition, array $request): array {
  if (!isset($definition['prompts'])) {
    return $definition['prompt'];
  }
  $on = quick_item_action_chosen_value($request, $definition['when_missing']) === 1;
  return $definition['prompts'][$on ? 'on' : 'off'];
}

/**
 * Answers one Quick item action on one of the owner's Items: snooze, kept
 * (Mark kept or not kept) or get-rid-of (Mark to get rid of or restore).
 * An unknown action throws InvalidArgumentException.
 *
 * $request is quick_item_action_request_from_globals()'s shape. The Item
 * changes only when may_change is true, which that function sets only for
 * a POST whose CSRF token already matches the session. Anything else leaves
 * the Item as it is: a POST that failed the check is 403, and any other
 * method is 405. With no artifact_id the answer is 400; a missing or
 * another owner's Item is 404, goes back to the Items page and changes
 * nothing. Otherwise the action changes its own field of the Item.
 *
 * Returns ['status' => int, 'body' => the JSON body, always with ok and
 * message, 'path' => the app path to go back to]. Sends nothing.
 */
function answer_quick_item_action(mysqli $db, int $owner_id, string $action, array $request): array {
  $definition = quick_item_action_definition($db, $owner_id, $action);
  $path = quick_item_action_return_path($definition, $request);

  if (empty($request['may_change'])) {
    $post = ($request['method'] ?? 'GET') === 'POST';
    return [
      'status' => $post ? 403 : 405,
      'body' => ['ok' => false, 'message' => $post
        ? 'Invalid CSRF token.'
        : 'Quick item actions accept a POST that has passed the CSRF check.'],
      'path' => $path,
    ];
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

  return ['status' => 200, 'body' => $definition['change']($items, $id, $item['Title'], $request), 'path' => $path];
}

/**
 * The request PHP's globals describe, in answer_quick_item_action()'s shape:
 * artifact_id, value and days as ints (null when missing), return_to (null
 * when missing), is_ajax (whether X-Requested-With is XMLHttpRequest),
 * method, and may_change. may_change is true only when this is a POST whose
 * CSRF token already matches the session.
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
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'may_change' => is_post_request() && validate_csrf_token(),
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
 * What this request should do with one Quick item action.
 *
 * A POST is answered by answer_quick_item_action(). That changes the Item
 * only when the request's may_change is true, and otherwise returns the
 * 403 for a CSRF token that did not match. Anything that is not a POST,
 * including a Daily email link, is a page that names the Item and posts
 * the same action with the CSRF token. Opening that page does not change
 * the Item.
 *
 * Returns ['answer' => answer_quick_item_action()'s answer] or
 * ['page' => ['title' => string, 'html' => the page body]].
 * An unknown action throws InvalidArgumentException.
 */
function quick_item_action_response(mysqli $db, int $owner_id, string $action, array $request): array {
  if (($request['method'] ?? 'GET') !== 'POST') {
    return ['page' => quick_item_action_page($db, $owner_id, $action, $request)];
  }
  return ['answer' => answer_quick_item_action($db, $owner_id, $action, $request)];
}

/**
 * The page a Daily email link opens: the question, the Item's name when it
 * is one of the owner's, and a POST form that carries the CSRF token.
 * A missing Item has no form. Nothing here writes the Item.
 *
 * Returns ['title' => string, 'html' => the <main> body].
 */
function quick_item_action_page(mysqli $db, int $owner_id, string $action, array $request): array {
  $definition = quick_item_action_definition($db, $owner_id, $action);
  [$title, $question, $submit] = quick_item_action_prompt($definition, $request);
  $back = quick_item_action_return_path($definition, $request);

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
    . '<form action="' . h(url_for($definition['path'])) . '" method="post">'
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
 * The three Quick item action pages. A POST is sent with
 * emit_quick_item_action_answer(). Anything else is the confirmation page,
 * which does not change the Item. $db is the connection footer.php closes.
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
