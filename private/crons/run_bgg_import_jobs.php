<?php
    // Runs the full BoardGameGeek imports queued on Settings. Cron starts it
    // every minute; one started while another runs does nothing.
    require_once(__DIR__ . '/../initialize.php');
    require_once(PRIVATE_PATH . '/bgg_import_jobs.php');

    // Log to stdout so crontab can append to the shared log volume. The
    // promoted release tree is root-owned and not writable by www-data.
    $ran = bgg_import_jobs_run_queued($db);
    if ($ran > 0) {
        echo date('Y-m-d G:i:s') . " ran $ran BGG import job(s)\n";
    }
