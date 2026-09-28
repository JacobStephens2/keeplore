<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/EventPlans.php');

// Adds, edits, packs or removes an event's items. The packed checkbox posts
// here with fetch and reads JSON; every other form redirects to the event.
if (!is_post_request()) {
    error_404();
}
$plans = new EventPlans($db, (int) $_SESSION['user_id']);
$event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$item_id = (int) filter_var($_POST['item_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$wants_json = $action === 'pack';
if (!$event_id) {
    error_404();
}

try {
    if ($action === 'add') {
        $ids = is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : [];
        $added = $plans->addItems($event_id, array_filter($ids, 'is_scalar'));
        $_SESSION['message'] = $added === 1 ? '1 game added.' : $added . ' games added.';
    } elseif ($action === 'update' && $item_id) {
        $plans->updateItem($event_id, $item_id, [
            'setting' => is_string($_POST['setting'] ?? null) ? $_POST['setting'] : '',
            'note' => is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
        ]);
        $_SESSION['message'] = 'Saved.';
    } elseif ($action === 'pack' && $item_id) {
        $plans->updateItem($event_id, $item_id, ['is_packed' => ($_POST['is_packed'] ?? '') === '1']);
    } elseif ($action === 'remove' && $item_id) {
        $plans->removeItem($event_id, $item_id);
        $_SESSION['message'] = 'Removed from this event.';
    } else {
        http_response_code(400);
        exit('Unknown action.');
    }
} catch (OutOfBoundsException $error) {
    error_404();
} catch (InvalidArgumentException $error) {
    if ($wants_json) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => $error->getMessage()]);
        exit;
    }
    $_SESSION['message'] = $error->getMessage();
}

if ($wants_json) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
redirect_to(url_for('/events/show.php?id=' . $event_id));
