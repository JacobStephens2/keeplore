<?php
    require_once(__DIR__ . '/../initialize.php');

    date_default_timezone_set('America/New_York');
    $current_hour = (int) date('G');

    // Log to stdout so crontab can append to the shared log volume. The
    // promoted release tree is root-owned and not writable by www-data.
    echo __FILE__ . " began running at " . date('Y-m-d G:i:s') . " (hour: $current_hour)\n";

    $mailer = SmtpMailer::fromEnvironment();

    foreach (Preferences::ownersDueDailyEmailAt($db, $current_hour) as $user_id) {

        echo "user id $user_id \n";

        $count_to_notify_about = (new DailyEmail($db, (int) $user_id, $mailer))->send();

        echo "count to notify about $count_to_notify_about \n";

    }

    echo __FILE__ . ' finished running at ' . date('Y-m-d G:i:s') . "\n";

?>
