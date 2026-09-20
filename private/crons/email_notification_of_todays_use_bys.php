<?php
    require_once(__DIR__ . '/../initialize.php');

    date_default_timezone_set('America/New_York');
    $current_hour = (int) date('G');

    // Log to stdout so crontab can append to the shared log volume. The
    // promoted release tree is root-owned and not writable by www-data.
    echo __FILE__ . " began running at " . date('Y-m-d G:i:s') . " (hour: $current_hour)\n";

    $stmt = mysqli_prepare($db, "SELECT id FROM users WHERE daily_email = 1 AND daily_email_hour = ?");
    mysqli_stmt_bind_param($stmt, "i", $current_hour);
    mysqli_stmt_execute($stmt);
    $users = mysqli_stmt_get_result($stmt);

    foreach ($users as $user) {

        $user_id = $user['id'];

        echo "user id $user_id \n";

        $count_to_notify_about = email_artifact_use_notice($user_id);

        echo "count to notify about $count_to_notify_about \n";

    }

    echo __FILE__ . ' finished running at ' . date('Y-m-d G:i:s') . "\n";

?>
