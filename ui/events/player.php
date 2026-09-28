<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/EventPlans.php');

// Adds or removes the players coming to an event, then returns to it.
if (!is_post_request()) {
    error_404();
}
$plans = new EventPlans($db, (int) $_SESSION['user_id']);
$event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$player_id = (int) filter_var($_POST['player_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if (!$event_id) {
    error_404();
}

try {
    if ($action === 'add') {
        $ids = is_array($_POST['player_ids'] ?? null) ? $_POST['player_ids'] : [];
        $added = $plans->addPlayers($event_id, array_filter($ids, 'is_scalar'));
        $_SESSION['message'] = $added === 1 ? '1 player added.' : $added . ' players added.';
    } elseif ($action === 'remove' && $player_id) {
        $plans->removePlayer($event_id, $player_id);
        $_SESSION['message'] = 'Player removed from this event.';
    } else {
        http_response_code(400);
        exit('Unknown action.');
    }
} catch (OutOfBoundsException $error) {
    error_404();
} catch (InvalidArgumentException $error) {
    $_SESSION['message'] = $error->getMessage();
}

redirect_to(url_for('/events/show.php?id=' . $event_id));
