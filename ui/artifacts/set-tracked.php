<?php
  require_once('../../private/initialize.php');
  require_once('../../private/quick_item_actions.php');
  require_login();

  present_quick_item_action($db, (int) $_SESSION['user_id'], 'kept');
?>
