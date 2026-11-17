<?php

require_once __DIR__ . '/item_facts.php';
require_once __DIR__ . '/use_by_date.php';
require_once __DIR__ . '/type_filter.php';
require_once __DIR__ . '/classes/Preferences.php';
require_once __DIR__ . '/classes/UseByQueue.php';

/**
 * The owner's complete Interact By page read: Type choices, normalized
 * filters, rows, table layout and overdue count. Shared rules stay with
 * the Type filter, Preferences, Use-by queue, Item facts and Use-by date.
 */
final class InteractByPage
{
    private string $today;

    /** $today is a Y-m-d day; the App day when omitted. */
    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? app_today();
    }

    /**
     * Reads no request globals and writes no domain records. Only the
     * shared Type selection (on POST) and Hide snoozed are remembered in
     * $session; unrelated keys survive. Storage failures propagate, keeping
     * session mutations that preceded the failure.
     */
    public function read(string $method, array $post, array &$session, bool $isGuest): array
    {
        $typeFilter = type_filter($this->db, $this->userId, $method, $post, $session);
        $defaultInterval = (new Preferences($this->db, $this->userId))->get()['default_use_interval'];
        $filters = $this->filters($method, $post, $session, $defaultInterval);
        $entries = (new UseByQueue($this->db, $this->userId, $this->today))->entries([
            'default_interval' => $filters['interval'],
            'type_ids' => $typeFilter['selected'],
            'sweet_spot' => $filters['sweetSpot'],
            'minimum_age' => $filters['minimumAge'],
            'include_secondary_collection' => $filters['shelfSort'] === 'yes',
            'hide_snoozed' => $filters['hideSnoozed'] === 'yes',
        ]);
        $rows = array_map(fn (array $entry) => $this->presentRow($entry), $entries);

        return [
            'type_filter' => $typeFilter,
            'filters' => $filters,
            'rows' => $rows,
            'table' => $this->table($filters, $isGuest),
            'total_overdue' => count(array_filter($rows, fn (array $row) => $row['overdue'])),
        ];
    }

    /** POST-only filters, with Hide snoozed remembered for subsequent GETs. */
    private function filters(string $method, array $post, array &$session, $defaultInterval): array
    {
        $isPost = strtoupper($method) === 'POST';
        $source = $isPost ? $post : [];
        $yesNo = fn ($value) => $value === 'yes' ? 'yes' : 'no';
        $hideSnoozed = $yesNo($isPost ? ($post['hideSnoozed'] ?? 'no') : ($session['hideSnoozed'] ?? 'yes'));
        $session['hideSnoozed'] = $hideSnoozed;

        return [
            'sweetSpot' => $source['sweetSpot'] ?? '',
            'minimumAge' => $source['minimumAge'] ?? 0,
            'shelfSort' => $yesNo($source['shelfSort'] ?? 'no'),
            'showAttributes' => $yesNo($source['showAttributes'] ?? 'no'),
            'showInterval' => $yesNo($source['showInterval'] ?? 'no'),
            'hideSnoozed' => $hideSnoozed,
            'interval' => use_by_view_interval($source['interval'] ?? $defaultInterval, $defaultInterval),
        ];
    }

    /** Queue fields and Item facts in the shapes the table and cards consume. */
    private function presentRow(array $entry): array
    {
        $counts = item_sweet_spot_counts($entry);
        $lastUse = $entry['last_use'] ?? null;

        return [
            'id' => (int) ($entry['id'] ?? 0),
            'title' => (string) ($entry['Title'] ?? ''),
            'type' => (string) ($entry['type'] ?? ''),
            'use_by_date' => (string) ($entry['use_by_date'] ?? ''),
            'overdue' => ($entry['status'] ?? null) === 'overdue',
            'last_use' => $lastUse === null ? null : (string) $lastUse,
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

    /** Column keys and DataTable ordering indices for this viewer and filter combination. */
    private function table(array $filters, bool $isGuest): array
    {
        $attributes = $filters['showAttributes'] === 'yes';
        $columns = ['name', 'use_by_date'];
        if (!$isGuest) {
            $columns[] = 'record';
        }
        $columns[] = 'type';
        if ($attributes) {
            array_push($columns, 'sws', 'avg_time', 'age', 'ss', 'mnp', 'mxp', 'candidate');
        }
        if (!$isGuest) {
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
}
