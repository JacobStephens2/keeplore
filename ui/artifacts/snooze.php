<?php
  require_once('../../private/initialize.php');
  require_once('../../private/quick_item_actions.php');
  require_login();

  $request = quick_item_action_request_from_globals();
  send_quick_item_action_answer($request, answer_quick_item_action($db, (int) $_SESSION['user_id'], 'snooze', $request));
?>
