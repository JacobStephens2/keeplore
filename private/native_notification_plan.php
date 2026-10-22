<?php

require_once __DIR__ . '/app_day.php';

/**
 * The native notification plan: which reminders an owner's device schedules
 * for Use-by queue entries, and on which day. The days are counted on the
 * app day by shifting each entry's use-by date: the lead that many days
 * before, the due reminder on the use-by date itself, and the overdue
 * reminder the next day. The shift moves through app_shift_day, so a
 * daylight-saving change cannot move the result a day.
 *
 * Each entry with an id, a title and a use-by date gets, in this order: a
 * lead reminder when the lead is greater than 0, a due reminder, and, when
 * overdue reminders are on, a dated overdue reminder. An entry whose use-by
 * status is overdue, and whose overdue reminders are on, also gets a
 * catch-up after those, with no day and the same id as the dated overdue
 * reminder. Entries stay in the queue's order across items.
 *
 * The plan never drops a reminder whose instant has passed: the server does
 * not know the device's zone or clock, so dropping "yesterday" on the app
 * day would cancel a reminder that is still later today on a device west of
 * New York, and keep one that already passed on a device east of it. The
 * device adapter drops an instant that is not strictly ahead.
 *
 * Takes queue entries (each with `id`, `Title`, `use_by_date` and the
 * use-by status vocabulary's `status`) and preferences with `lead_days` and
 * `past_due`, and returns reminders, each with `id` (the item id times 10,
 * plus 1 for the lead, 2 for the due and 3 for the overdue one), `title`,
 * `body`, `day` (Y-m-d, or null for a catch-up) and `item_id`. No database
 * and no clock.
 */
function native_notification_reminders(array $entries, array $prefs): array
{
    $lead = (int) ($prefs['lead_days'] ?? 0);
    $withOverdue = !empty($prefs['past_due']);

    $reminders = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = $entry['id'] ?? null;
        $title = $entry['Title'] ?? $entry['title'] ?? null;
        $useBy = $entry['use_by_date'] ?? null;
        if (empty($id) || empty($title) || empty($useBy)) {
            continue;
        }
        $id = (int) $id;
        $title = (string) $title;
        $useBy = (string) $useBy;

        if ($lead > 0) {
            $reminders[] = [
                'id' => $id * 10 + 1,
                'title' => 'Due soon',
                'body' => $title . ' is due ' . $useBy,
                'day' => app_shift_day($useBy, -$lead),
                'item_id' => $id,
            ];
        }

        $reminders[] = [
            'id' => $id * 10 + 2,
            'title' => 'Due today',
            'body' => $title . ' is due today',
            'day' => $useBy,
            'item_id' => $id,
        ];

        if ($withOverdue) {
            $reminders[] = [
                'id' => $id * 10 + 3,
                'title' => 'Overdue',
                'body' => $title . ' is overdue',
                'day' => app_shift_day($useBy, 1),
                'item_id' => $id,
            ];

            if (($entry['status'] ?? null) === 'overdue') {
                $reminders[] = [
                    'id' => $id * 10 + 3,
                    'title' => 'Overdue',
                    'body' => $title . ' is overdue (due ' . $useBy . ')',
                    'day' => null,
                    'item_id' => $id,
                ];
            }
        }
    }
    return $reminders;
}
