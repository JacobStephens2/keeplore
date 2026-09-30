<?php
// Settings' "Import all <user> ratings": queue a full import of the owner's
// BoardGameGeek reviewer for the cron worker, then return to Settings.
// initialize.php checks the CSRF token on every POST.
require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/bgg_import_jobs.php');
require_login();

if (is_post_request()) {
  // Settings' status line says the import is queued; only a refusal needs
  // its own message.
  $result = bgg_import_job_queue($db, (int) $_SESSION['user_id']);
  if (!$result['ok']) {
    $_SESSION['message'] = $result['error'];
  }
}
redirect_to(url_for('/settings/edit.php') . '#bgg_import');
