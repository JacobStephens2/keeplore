<?php

/**
 * Interact By page: its filters, the Use-by queue options they select, its
 * rows and its table's layout. The page reads the queue with
 * interact_by_queue_options() and renders each entry through
 * interact_by_present_row(); every rule it shares with another page is read
 * from Item facts or the Use-by date module.
 */

require_once __DIR__ . '/item_facts.php';
require_once __DIR__ . '/use_by_date.php';

/**
 * The page's filters: sweetSpot and minimumAge as typed (the Use-by queue
 * reads them), shelfSort, showAttributes, showInterval and hideSnoozed as
 * 'yes' or 'no', and the interval the queue is viewed with.
 *
 * A POST reads the filter form. Hide snoozed is remembered in $session, as
 * the Type filter remembers its selection, and a GET reads it from there,
 * hiding snoozed items when nothing is remembered.
 */
function interact_by_filters_from_request(string $method, array $post, array &$session, $default_interval) {
    $is_post = strtoupper($method) === 'POST';
    $source = $is_post ? $post : [];
    $yes_no = fn ($value) => $value === 'yes' ? 'yes' : 'no';

    $hide_snoozed = $yes_no($is_post ? ($post['hideSnoozed'] ?? 'no') : ($session['hideSnoozed'] ?? 'yes'));
    $session['hideSnoozed'] = $hide_snoozed;

    return [
        'sweetSpot' => $source['sweetSpot'] ?? '',
        'minimumAge' => $source['minimumAge'] ?? 0,
        'shelfSort' => $yes_no($source['shelfSort'] ?? 'no'),
        'showAttributes' => $yes_no($source['showAttributes'] ?? 'no'),
        'showInterval' => $yes_no($source['showInterval'] ?? 'no'),
        'hideSnoozed' => $hide_snoozed,
        'interval' => use_by_view_interval($source['interval'] ?? $default_interval, $default_interval),
    ];
}

/** UseByQueue::entries' options for the page's filters and the Type filter's selected ids. */
function interact_by_queue_options(array $filters, array $type_ids) {
    return [
        'default_interval' => $filters['interval'],
        'type_ids' => $type_ids,
        'sweet_spot' => $filters['sweetSpot'],
        'minimum_age' => $filters['minimumAge'],
        'include_secondary_collection' => $filters['shelfSort'] === 'yes',
        'hide_snoozed' => $filters['hideSnoozed'] === 'yes',
    ];
}

/**
 * One Use-by queue entry as a table row. last_use is null when the item has
 * never been used. The attribute columns: sws is the lowest count in the
 * Sweet spot, age the minimum age, both '' when none is recorded; ss, mnp and
 * mxp are the queue's columns as stored; candidate is whether the item is a Candidate.
 */
function interact_by_present_row(array $entry) {
    $counts = item_sweet_spot_counts($entry);
    $last_use = $entry['last_use'] ?? null;

    return [
        'id' => (int) ($entry['id'] ?? 0),
        'title' => (string) ($entry['Title'] ?? ''),
        'type' => (string) ($entry['type'] ?? ''),
        'use_by_date' => (string) ($entry['use_by_date'] ?? ''),
        'overdue' => ($entry['status'] ?? null) === 'overdue',
        'last_use' => $last_use === null ? null : (string) $last_use,
        'acq' => (string) ($entry['Acq'] ?? ''),
        'interval' => $entry['interval'] ?? null,
        'is_snoozed' => (bool) ($entry['is_snoozed'] ?? false),
        'snoozed_until' => (string) ($entry['snoozed_until'] ?? ''),
        'sws' => $counts === [] ? '' : $counts[0],
        'avg_time' => item_average_play_time($entry),
        'age' => item_min_age($entry) ?? '',
        'ss' => (string) ($entry['ss'] ?? ''),
        'mnp' => (string) ($entry['mnp'] ?? ''),
        'mxp' => (string) ($entry['mxp'] ?? ''),
        'candidate' => item_is_candidate($entry),
    ];
}

/**
 * The table's layout: 'columns', its column keys in order, and 'order', the
 * DataTable order as [column index, 'asc' or 'desc'] pairs. A guest has no
 * record or get rid of columns; attributes add theirs after type, and the
 * interval column comes last. Shelf sort orders by type and the attributes,
 * so only with attributes shown; otherwise attributes order by interact by,
 * average time and age, and the default is interact by, recent interaction
 * and tracking start.
 */
function interact_by_table(array $filters, bool $is_guest) {
    $attributes = $filters['showAttributes'] === 'yes';

    $columns = ['name', 'use_by_date'];
    if (!$is_guest) {
        $columns[] = 'record';
    }
    $columns[] = 'type';
    if ($attributes) {
        array_push($columns, 'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate');
    }
    if (!$is_guest) {
        $columns[] = 'get_rid_of';
    }
    array_push($columns, 'overdue', 'last_use', 'acq');
    if ($filters['showInterval'] === 'yes') {
        $columns[] = 'interval';
    }

    if ($attributes && $filters['shelfSort'] === 'yes') {
        $order = [
            ['type', 'asc'], ['sws', 'asc'], ['avg_time', 'asc'], ['age', 'asc'],
            ['ss', 'asc'], ['mnp', 'asc'], ['mxp', 'asc'], ['last_use', 'desc'], ['candidate', 'desc'],
        ];
    } elseif ($attributes) {
        $order = [['use_by_date', 'asc'], ['avg_time', 'asc'], ['age', 'asc']];
    } else {
        $order = [['use_by_date', 'asc'], ['last_use', 'asc'], ['acq', 'asc']];
    }
    $index = array_flip($columns);

    return [
        'columns' => $columns,
        'order' => array_map(fn (array $sort) => [$index[$sort[0]], $sort[1]], $order),
    ];
}
