<?php

require_once dirname(__DIR__) . '/use_by_date.php';
require_once dirname(__DIR__) . '/app_day.php';
require_once dirname(__DIR__) . '/item_facts.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Preferences.php';

/**
 * The owner's Use-by queue: kept items not flagged to get rid of, plus the
 * secondary collection when asked, each with its last use, use-by date and
 * whether it is overdue, due today or upcoming. Every page, email and
 * notification that shows what is due reads it from here.
 *
 * Each entry keeps the queue's stored fields, with `type`, `last_use` (Y-m-d
 * or null) and use_by_status()'s answer on the queue's day: `interval`,
 * `use_by_date`, `days_until`, `status` and `is_snoozed`. How soon counts as
 * "due soon" is each caller's choice.
 */
final class UseByQueue
{
    /** The queue's stored fields, in their existing order and casing. */
    private const STORED_FIELDS = [
        'id', 'Title', 'Acq', 'interaction_frequency_days', 'type_id', 'type',
        'user_id', 'is_kept', 'is_in_secondary_collection', 'to_get_rid_of',
        'snoozed_until', 'mnp', 'mxp', 'mnt', 'mxt', 'Candidate', 'UsedRecUserCt',
        'ss', 'age', 'last_use',
    ];

    private string $today;

    /** $today is a Y-m-d day; the App day (app_today()) when omitted. */
    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? app_today();
    }

    public function today(): string
    {
        return $this->today;
    }

    /**
     * The queue, ordered by use-by date with undated items last, then by
     * last use (never used first). Filters:
     * default_interval (the owner's default use interval when absent),
     * type_ids (null for every type; an empty array returns nothing),
     * sweet_spot (a player count: keeps the items whose Sweet spot includes
     * it; anything but a whole number of 1 or more means no filter),
     * minimum_age (keeps items whose own minimum age is at or above it),
     * include_secondary_collection, hide_snoozed.
     */
    public function entries(array $filters = []): array
    {
        $typeIds = $filters['type_ids'] ?? null;
        if (is_array($typeIds) && !$typeIds) {
            return [];
        }

        $includeSecondary = !empty($filters['include_secondary_collection']);
        $rows = (new Items($this->db, $this->userId))->list([
            'type_ids' => is_array($typeIds) ? $typeIds : null,
            'kept' => $includeSecondary ? null : true,
        ]);
        $minimumAge = $filters['minimum_age'] ?? '';
        $filterAge = !in_array($minimumAge, ['', 0, '0', null], true);
        // Decision 6 of #312 requires floating numeric-prefix conversion,
        // including decimals and scalar values such as false.
        $ageThreshold = (float) (string) $minimumAge;
        // A whole number of 1 or more, read as the Items page reads its count.
        $sweetSpot = (string) ($filters['sweet_spot'] ?? '');
        $filterSweetSpot = preg_match('/^\s*[1-9]\d*\s*$/', $sweetSpot);
        $interval = $filters['default_interval'] ?? $this->defaultUseInterval();
        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->present($row, $interval);
            if (!artifact_is_kept($entry) && !($includeSecondary && artifact_is_in_secondary_collection($entry))) {
                continue;
            }
            // Only zero or null is eligible; the generic false-flag filter
            // also accepts anomalous non-one values that this queue excludes.
            if ($entry['to_get_rid_of'] !== null && $entry['to_get_rid_of'] != 0) {
                continue;
            }
            if ($filterAge && ($entry['age'] === null || (float) $entry['age'] < $ageThreshold)) {
                continue;
            }
            if ($filterSweetSpot && !item_plays_best_at($entry, (int) $sweetSpot)) {
                continue;
            }
            if (!empty($filters['hide_snoozed']) && $entry['is_snoozed']) {
                continue;
            }
            $entries[] = $entry;
        }
        usort($entries, fn (array $a, array $b) =>
            [$a['use_by_date'] === null, $a['use_by_date'], $a['last_use'], (int) $a['id']]
            <=> [$b['use_by_date'] === null, $b['use_by_date'], $b['last_use'], (int) $b['id']]);
        return $entries;
    }

    /** One of the owner's items as a queue entry, whatever its kept state, or null. */
    public function entry(int $itemId): ?array
    {
        $row = (new Items($this->db, $this->userId))->list(['ids' => [$itemId]])[0] ?? null;
        return $row === null ? null : $this->present($row, $this->defaultUseInterval());
    }

    private function defaultUseInterval(): float
    {
        return (new Preferences($this->db, $this->userId))->get()['default_use_interval'];
    }

    private function present(array $row, $defaultInterval): array
    {
        $columns = array_change_key_case($row, CASE_LOWER);
        $entry = [];
        foreach (self::STORED_FIELDS as $field) {
            $entry[$field] = $columns[$field === 'type' ? 'type_name' : strtolower($field)];
        }
        return $entry + use_by_status($entry, $defaultInterval, $this->today);
    }
}
