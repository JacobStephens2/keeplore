<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/Preferences.php';
require_once __DIR__ . '/classes/UseByQueue.php';

/** How many days ahead upcoming uses reach. */
const UPCOMING_USES_HORIZON_DAYS = 60;

/**
 * GET /upcoming-interactions.php: the owner's Use-by queue entries due
 * within 60 days, for native notifications, with the owner's notification
 * Preferences. The master key, which has no queue of its own, gets a 400.
 *
 * Returns [status, response fields].
 */
function list_upcoming_uses_over_api(mysqli $db, ApiCaller $caller): array {
  $owner = $caller->owner();
  if ($owner === null) {
    return [400, ['message' => 'This endpoint requires user-scoped authentication (JWT cookie).']];
  }

  $preferences = (new Preferences($db, $owner))->get();
  $default_interval = $preferences['default_use_interval'];
  $queue = new UseByQueue($db, $owner);

  $items = [];
  foreach ($queue->entries(['default_interval' => $default_interval]) as $entry) {
    if ($entry['use_by_date'] === null || $entry['days_until'] > UPCOMING_USES_HORIZON_DAYS) {
      continue;
    }

    $items[] = [
      'id' => (int) $entry['id'],
      'title' => $entry['Title'],
      'use_by_date' => $entry['use_by_date'],
      'most_recent_interaction' => $entry['last_use'],
      'interval_days' => ($entry['interaction_frequency_days'] !== null)
        ? (float) $entry['interaction_frequency_days']
        : $default_interval,
      'status' => match (true) {
        $entry['status'] === 'overdue' => 'past_due',
        $entry['status'] === 'due_today' => 'due_today',
        $entry['days_until'] <= 7 => 'due_soon',
        default => 'upcoming',
      },
    ];
  }

  return [200, [
    'authenticated' => true,
    'today' => $queue->today(),
    'timezone' => 'America/New_York',
    'horizon_days' => UPCOMING_USES_HORIZON_DAYS,
    'default_interval_days' => $default_interval,
    'notification_prefs' => [
      'enabled' => $preferences['native_notify_enabled'],
      'hour' => $preferences['native_notify_hour'],
      'lead_days' => $preferences['native_notify_lead_days'],
      'past_due' => $preferences['native_notify_past_due'],
    ],
    'items' => $items,
  ]];
}

?>
