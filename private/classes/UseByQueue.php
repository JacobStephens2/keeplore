<?php

require_once dirname(__DIR__) . '/use_by_date.php';
require_once dirname(__DIR__) . '/record_use.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Preferences.php';

/**
 * The owner's Use-by queue: kept items not flagged to get rid of, plus the
 * secondary collection when asked, each with its last use, use-by date and
 * whether it is overdue, due today or upcoming. Every page, email and
 * notification that shows what is due reads it from here.
 *
 * Each entry is the item's own columns plus `type`, `last_use` (Y-m-d or
 * null) and use_by_status()'s answer on the queue's day: `interval`,
 * `use_by_date`, `days_until`, `status` and `is_snoozed`. How soon counts as
 * "due soon" is each caller's choice.
 */
final class UseByQueue
{
    private string $today;

    /** $today is a Y-m-d day; the app's America/New_York day when omitted. */
    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? record_use_today();
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
     * sweet_spot, minimum_age, include_secondary_collection, hide_snoozed.
     */
    public function entries(array $filters = []): array
    {
        $typeIds = $filters['type_ids'] ?? null;
        if (is_array($typeIds) && !$typeIds) {
            return [];
        }

        $where = ['(games.to_get_rid_of = 0 OR games.to_get_rid_of IS NULL)'];
        $types = '';
        $params = [];

        $where[] = empty($filters['include_secondary_collection'])
            ? 'games.is_kept = 1'
            : '(games.is_kept = 1 OR games.is_in_secondary_collection = 1)';

        if (!empty($filters['hide_snoozed'])) {
            // use_by_status()'s is_snoozed rule, kept in SQL so hidden rows are never fetched.
            $where[] = '(games.snoozed_until IS NULL OR games.snoozed_until <= ?)';
            $types .= 's';
            $params[] = $this->today;
        }

        $sweetSpot = (string) ($filters['sweet_spot'] ?? '');
        if ($sweetSpot !== '') {
            $patterns = [
                $sweetSpot,
                $sweetSpot . ' %',
                '%0' . $sweetSpot . '%',
                '%,' . $sweetSpot,
                '%,' . $sweetSpot . ',%',
                '%, ' . $sweetSpot,
                '%, ' . $sweetSpot . ',%',
            ];
            $where[] = '(' . implode(' OR ', array_fill(0, count($patterns), 'games.ss LIKE ?')) . ')';
            $types .= str_repeat('s', count($patterns));
            array_push($params, ...$patterns);
        }

        $minimumAge = $filters['minimum_age'] ?? '';
        if ($minimumAge !== '' && $minimumAge !== 0 && $minimumAge !== '0' && $minimumAge !== null) {
            $where[] = 'games.age >= ?';
            $types .= 's';
            $params[] = (string) $minimumAge;
        }

        if (is_array($typeIds)) {
            $where[] = 'games.type_id IN (' . implode(',', array_fill(0, count($typeIds), '?')) . ')';
            $types .= str_repeat('s', count($typeIds));
            array_push($params, ...array_map('strval', array_values($typeIds)));
        }

        $interval = $filters['default_interval'] ?? $this->defaultUseInterval();
        $entries = array_map(
            fn (array $row) => $this->present($row, $interval),
            $this->rows(implode(' AND ', $where), $types, $params)
        );
        usort($entries, fn (array $a, array $b) =>
            [$a['use_by_date'] === null, $a['use_by_date'], $a['last_use'], (int) $a['id']]
            <=> [$b['use_by_date'] === null, $b['use_by_date'], $b['last_use'], (int) $b['id']]);
        return $entries;
    }

    /** One of the owner's items as a queue entry, whatever its kept state, or null. */
    public function entry(int $itemId): ?array
    {
        $row = $this->rows('games.id = ?', 'i', [$itemId])[0] ?? null;
        return $row === null ? null : $this->present($row, $this->defaultUseInterval());
    }

    private function defaultUseInterval(): float
    {
        return (new Preferences($this->db, $this->userId))->get()['default_use_interval'];
    }

    private function present(array $row, $defaultInterval): array
    {
        return $row + use_by_status($row, $defaultInterval, $this->today);
    }

    /**
     * The owner's items matching $where, with their last use read from
     * Items' last-use rule so no row repeats.
     */
    private function rows(string $where, string $types, array $params): array
    {
        $stmt = $this->db->prepare(
            "SELECT games.id, games.Title, games.Acq, games.interaction_frequency_days, games.type_id,
                types.objectType AS type, games.user_id, games.is_kept, games.is_in_secondary_collection,
                games.to_get_rid_of, games.snoozed_until, games.mnp, games.mxp, games.mnt, games.mxt,
                games.Candidate, games.UsedRecUserCt, games.ss, games.age,
                item_last_use.last_use
             FROM games
                LEFT JOIN types ON types.id = games.type_id
                LEFT JOIN " . Items::LAST_USE . " item_last_use ON item_last_use.artifact_id = games.id
             WHERE games.user_id = ? AND $where"
        );
        $stmt->bind_param('i' . $types, $this->userId, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
