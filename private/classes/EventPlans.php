<?php

require_once dirname(__DIR__) . '/items_list.php';
require_once dirname(__DIR__) . '/item_types.php';
require_once dirname(__DIR__) . '/kept_status.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/People.php';

/**
 * The owner's events, such as a beach week, the items planned for each, and
 * the owner's players coming to each. An event item carries the event's own
 * setting, note and packed mark; the item's facts (players, sweet spot, age,
 * tags) are read from the item through Items.
 * Another user's event reads as absent and throws OutOfBoundsException on
 * change; bad input throws InvalidArgumentException.
 */
final class EventPlans
{
    private Items $items;
    private People $people;

    public function __construct(private mysqli $db, private int $userId)
    {
        $this->items = new Items($db, $userId);
        $this->people = new People($db, $userId);
    }

    /** Every event, latest start first and undated last, with item, packed and player counts. */
    public function all(): array
    {
        $events = $this->rows(
            'SELECT e.id, e.name, e.starts_on, e.ends_on,
                COUNT(ei.artifact_id) AS item_count, COALESCE(SUM(ei.is_packed), 0) AS packed_count,
                (SELECT COUNT(*) FROM event_players ep JOIN players p ON p.id = ep.player_id AND p.user_id = e.user_id
                 WHERE ep.event_id = e.id) AS player_count
             FROM events e LEFT JOIN event_items ei ON ei.event_id = e.id
             WHERE e.user_id = ?
             GROUP BY e.id, e.name, e.starts_on, e.ends_on
             ORDER BY e.starts_on IS NULL, e.starts_on DESC, e.name ASC, e.id ASC',
            'i', [$this->userId]
        );
        foreach ($events as &$event) {
            $event['id'] = (int) $event['id'];
            $event['item_count'] = (int) $event['item_count'];
            $event['packed_count'] = (int) $event['packed_count'];
            $event['player_count'] = (int) $event['player_count'];
        }
        return $events;
    }

    /**
     * The event with its items in title order and its players youngest first,
     * those without a birth year last, or null when it is not the owner's.
     */
    public function find(int $id): ?array
    {
        $event = $this->rows(
            'SELECT id, name, starts_on, ends_on, notes FROM events WHERE id = ? AND user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
        if ($event === null) {
            return null;
        }
        $event['id'] = (int) $event['id'];
        $event['notes'] = (string) $event['notes'];
        $planned = $this->plannedItems($id);
        $number = fn ($value) => $value === null ? null : (int) $value;
        // Items leaves out a planned item that isn't the owner's.
        $event['items'] = array_map(function ($item) use ($planned, $number) {
            $plan = $planned[$item['id']];
            return [
                'id' => (int) $item['id'],
                'Title' => $item['Title'],
                'MnP' => $number($item['MnP']),
                'MxP' => $number($item['MxP']),
                'SS' => (string) $item['SS'],
                'Age' => $number($item['Age']),
                'MnT' => $number($item['MnT']),
                'MxT' => $number($item['MxT']),
                'is_kept' => artifact_is_kept($item),
                'setting' => $plan['setting'],
                'note' => $plan['note'],
                'is_packed' => (bool) $plan['is_packed'],
                'tags' => $item['tags'],
            ];
        }, $this->items->list(['ids' => array_keys($planned)]));
        $players = $this->players($id, true, $this->year($event['starts_on']));
        // Unknown ages last, then youngest first; usort is stable (PHP 8), so
        // players of one age stay in name order.
        $key = fn($player) => [$player['age'] === null, $player['age']];
        usort($players, fn($a, $b) => $key($a) <=> $key($b));
        $event['players'] = $players;
        return $event;
    }

    /**
     * Every item the owner has in Keeplore not yet planned for the event, kept
     * or not, so a game being considered for purchase can be tried in the
     * plan. In title order, each with its play facts line, whether it is
     * kept, and whether its type is a game.
     */
    public function itemsToAdd(int $eventId): array
    {
        $planned = $this->plannedItems($eventId);
        $items = array_filter($this->items->list(), fn ($item) => !isset($planned[$item['id']]));
        return array_map(fn($item) => [
            'id' => (int) $item['id'],
            'Title' => $item['Title'],
            // Players, sweet spot and age, without the play time.
            'facts' => items_list_play_facts(array_intersect_key($item, array_flip(['MnP', 'MxP', 'SS', 'Age']))),
            'is_kept' => artifact_is_kept($item),
            'is_game' => item_type_is_game($item['type_name']),
        ], array_values($items));
    }

    /** The owner's players not yet coming to the event, in name order. */
    public function playersToAdd(int $eventId): array
    {
        $startsOn = $this->rows(
            'SELECT starts_on FROM events WHERE id = ? AND user_id = ?', 'ii', [$eventId, $this->userId]
        )[0]['starts_on'] ?? null;
        return $this->players($eventId, false, $this->year($startsOn));
    }

    /** Creates the event, or renames and redates it with $id. Returns its id. */
    public function save(array $input, ?int $id = null): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Name the event in 255 characters or fewer.');
        }
        $startsOn = $this->optionalDate($input['starts_on'] ?? '');
        $endsOn = $this->optionalDate($input['ends_on'] ?? '');
        if ($startsOn !== null && $endsOn !== null && $startsOn > $endsOn) {
            throw new InvalidArgumentException('The event must end on or after the day it starts.');
        }
        $notes = trim((string) ($input['notes'] ?? ''));
        if ($id === null) {
            $this->statement(
                'INSERT INTO events (user_id, name, starts_on, ends_on, notes) VALUES (?, ?, ?, ?, ?)',
                'issss', [$this->userId, $name, $startsOn, $endsOn, $notes]
            )->close();
            return (int) $this->db->insert_id;
        }
        $this->requireEvent($id);
        $this->statement(
            'UPDATE events SET name = ?, starts_on = ?, ends_on = ?, notes = ? WHERE id = ? AND user_id = ?',
            'ssssii', [$name, $startsOn, $endsOn, $notes, $id, $this->userId]
        )->close();
        return $id;
    }

    public function delete(int $id): void
    {
        $this->requireEvent($id);
        $this->statement('DELETE FROM events WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
    }

    /**
     * Plans the owner's items for the event. An item already planned keeps
     * its setting, note and packed mark. Returns how many were new.
     */
    public function addItems(int $eventId, array $itemIds): int
    {
        $this->requireEvent($eventId);
        $itemIds = $this->distinctIds($itemIds);
        if (count($this->items->list(['ids' => $itemIds])) !== count($itemIds)) {
            throw new InvalidArgumentException('Choose items from your own items in Keeplore.');
        }
        return $this->linkToEvent('event_items', 'artifact_id', $eventId, $itemIds);
    }

    /** Adds the owner's players to the event. Returns how many were new. */
    public function addPlayers(int $eventId, array $playerIds): int
    {
        $this->requireEvent($eventId);
        $playerIds = $this->ownPlayerIds($playerIds);
        return $this->linkToEvent('event_players', 'player_id', $eventId, $playerIds);
    }

    public function removePlayer(int $eventId, int $playerId): void
    {
        $this->requireEvent($eventId);
        $this->statement(
            'DELETE FROM event_players WHERE event_id = ? AND player_id = ?',
            'ii', [$eventId, $playerId]
        )->close();
    }

    /** Sets whichever of setting, note and is_packed $input names. */
    public function updateItem(int $eventId, int $itemId, array $input): void
    {
        $this->requireEvent($eventId);
        $limits = ['setting' => 64, 'note' => 255];
        foreach ($limits as $field => $limit) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = trim((string) $input[$field]);
            if (mb_strlen($value) > $limit) {
                throw new InvalidArgumentException("Keep the {$field} to {$limit} characters or fewer.");
            }
            $this->updateItemField($eventId, $itemId, $field, 's', $value);
        }
        if (array_key_exists('is_packed', $input)) {
            $this->updateItemField($eventId, $itemId, 'is_packed', 'i', empty($input['is_packed']) ? 0 : 1);
        }
    }

    public function removeItem(int $eventId, int $itemId): void
    {
        $this->requireEvent($eventId);
        $this->statement(
            'DELETE FROM event_items WHERE event_id = ? AND artifact_id = ?',
            'ii', [$eventId, $itemId]
        )->close();
    }

    private function updateItemField(int $eventId, int $itemId, string $field, string $type, $value): void
    {
        // $field is one of this class's own column names, never input.
        $this->statement(
            "UPDATE event_items SET {$field} = ? WHERE event_id = ? AND artifact_id = ?",
            $type . 'ii', [$value, $eventId, $itemId]
        )->close();
    }

    /**
     * The owner's players coming to the event, or with $coming false those
     * not, named "First Last", in name order (find() reorders by age). Each age is the one the player
     * turns in $year, or null without a birth year.
     */
    private function players(int $eventId, bool $coming, int $year): array
    {
        $players = $this->rows(
            "SELECT p.id, TRIM(CONCAT(COALESCE(p.FirstName, ''), ' ', COALESCE(p.LastName, ''))) AS name, p.birth_year
             FROM players p LEFT JOIN event_players ep ON ep.player_id = p.id AND ep.event_id = ?
             WHERE p.user_id = ? AND (ep.player_id IS NOT NULL) = ?
             ORDER BY p.FirstName ASC, p.LastName ASC, p.id ASC",
            'iii', [$eventId, $this->userId, (int) $coming]
        );
        return array_map(fn($player) => [
            'id' => (int) $player['id'],
            'name' => $player['name'],
            'age' => $player['birth_year'] === null ? null : $year - (int) $player['birth_year'],
        ], $players);
    }

    /** The year an event starting on $startsOn is counted in: that year, or this one undated. */
    private function year(?string $startsOn): int
    {
        return (int) ($startsOn === null ? date('Y') : substr($startsOn, 0, 4));
    }

    /**
     * The event's own setting, note and packed mark for each item planned
     * for it, keyed by item id.
     */
    private function plannedItems(int $eventId): array
    {
        return array_column($this->rows(
            'SELECT artifact_id, setting, note, is_packed FROM event_items WHERE event_id = ?',
            'i', [$eventId]
        ), null, 'artifact_id');
    }

    /** The distinct positive ids among $ids. */
    private function distinctIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    }

    /** The distinct positive ids, each checked to be one of the owner's players. */
    private function ownPlayerIds(array $ids): array
    {
        $ids = $this->distinctIds($ids);
        if (count($this->people->own($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Choose players from your own people list.');
        }
        return $ids;
    }

    /**
     * Links each id to the event in $table's $column (this class's own
     * names, never input), leaving existing links alone. Returns how many
     * were new.
     */
    private function linkToEvent(string $table, string $column, int $eventId, array $ids): int
    {
        $added = 0;
        foreach ($ids as $id) {
            $stmt = $this->statement(
                "INSERT IGNORE INTO {$table} (event_id, {$column}) VALUES (?, ?)",
                'ii', [$eventId, $id]
            );
            $added += $stmt->affected_rows;
            $stmt->close();
        }
        return $added;
    }

    private function requireEvent(int $id): void
    {
        if (!$this->rows('SELECT id FROM events WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])) {
            throw new OutOfBoundsException('Event not found.');
        }
    }

    private function optionalDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Enter dates in YYYY-MM-DD format.');
        }
        return $value;
    }

    private function statement(string $sql, string $types, array $params): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt;
    }

    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->statement($sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
