<?php
// The owner's latest BoardGameGeek import, polled by Settings while it runs.
require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/bgg_import_jobs.php');
require_login();

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(bgg_import_job_view($db, (int) $_SESSION['user_id']));
