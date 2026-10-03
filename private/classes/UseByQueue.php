<?php

require_once dirname(__DIR__) . '/use_by_date.php';
require_once dirname(__DIR__) . '/record_use.php';

/**
 * The owner's Use-by queue: kept items not flagged to get rid of, plus the
 * secondary collection when asked, each with its last use, use-by date and
 * whether it is overdue, due today or upcoming. Every page, email and
 * notification that shows what is due reads it from here.
 *
 * Each entry is the item's own columns plus `type`, `last_use`,
 * `use_by_date` (both Y-m-d or null), `days_until` (signed whole days from
 * today, null without a use-by date) and `status` (`overdue`, `due_today`,
 * `upcoming`, or null without a use-by date). How soon counts as "due soon"
 * is each caller's choice.
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
     * The queue, ordered by use-by date with undated items last. Filters:
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

        $interval = $filters['default_interval'] ?? default_use_interval($this->db, $this->userId);
        $entries = array_map(
            fn (array $row) => $this->present($row, $interval),
            $this->rows(implode(' AND ', $where), $types, $params)
        );
        usort($entries, fn (array $a, array $b) =>
            [$a['use_by_date'] === null, $a['use_by_date']] <=> [$b['use_by_date'] === null, $b['use_by_date']]);
        return $entries;
    }

    /** One of the owner's items as a queue entry, whatever its kept state, or null. */
    public function entry(int $itemId): ?array
    {
        $row = $this->rows('games.id = ?', 'i', [$itemId])[0] ?? null;
        return $row === null ? null : $this->present($row, default_use_interval($this->db, $this->userId));
    }

    private function present(array $row, $defaultInterval): array
    {
        $lastUse = max((string) $row['last_use'], (string) $row['last_play']);
        $lastUse = $lastUse === '' ? null : substr($lastUse, 0, 10);
        unset($row['last_use'], $row['last_play']);
        $useBy = use_by_date($row['Acq'], $lastUse, $row['interaction_frequency_days'], $defaultInterval);
        $daysUntil = $useBy === null ? null : (int) (new DateTimeImmutable($this->today, new DateTimeZone('UTC')))
            ->diff(new DateTimeImmutable($useBy, new DateTimeZone('UTC')))->format('%r%a');

        return $row + [
            'last_use' => $lastUse,
            'use_by_date' => $useBy,
            'days_until' => $daysUntil,
            'status' => match (true) {
                $daysUntil === null => null,
                $daysUntil < 0 => 'overdue',
                $daysUntil === 0 => 'due_today',
                default => 'upcoming',
            },
        ];
    }

    /**
     * The owner's items matching $where, with their latest use and latest
     * legacy play date read from per-item aggregates so no row repeats.
     */
    private function rows(string $where, string $types, array $params): array
    {
        $stmt = $this->db->prepare(
            "SELECT games.id, games.Title, games.Acq, games.interaction_frequency_days, games.type_id,
                types.objectType AS type, games.user_id, games.is_kept, games.is_in_secondary_collection,
                games.to_get_rid_of, games.snoozed_until, games.mnp, games.mxp, games.mnt, games.mxt,
                games.Candidate, games.UsedRecUserCt, games.ss, games.age,
                recent_use.last_use, recent_play.last_play
             FROM games
                LEFT JOIN types ON types.id = games.type_id
                LEFT JOIN (SELECT artifact_id, MAX(use_date) AS last_use FROM uses GROUP BY artifact_id) recent_use
                    ON recent_use.artifact_id = games.id
                LEFT JOIN (SELECT Title, MAX(PlayDate) AS last_play FROM responses GROUP BY Title) recent_play
                    ON recent_play.Title = games.id
             WHERE games.user_id = ? AND $where"
        );
        $stmt->bind_param('i' . $types, $this->userId, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
