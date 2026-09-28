<?php

require_once dirname(__DIR__) . '/item_tags.php';
require_once dirname(__DIR__) . '/items_list.php';
require_once dirname(__DIR__) . '/item_types.php';
require_once dirname(__DIR__) . '/kept_status.php';

/**
 * The owner's events, such as a beach week, and the items planned for each.
 * An event item carries the event's own setting, note and packed mark; the
 * item's facts (players, sweet spot, age, tags) come from the item.
 * Another user's event reads as absent and throws OutOfBoundsException on
 * change; bad input throws InvalidArgumentException.
 */
final class EventPlans
{
    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** Every event, latest start first and undated last, with item and packed counts. */
    public function all(): array
    {
        $events = $this->rows(
            'SELECT e.id, e.name, e.starts_on, e.ends_on,
                COUNT(ei.artifact_id) AS item_count, COALESCE(SUM(ei.is_packed), 0) AS packed_count
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
        }
        return $events;
    }

    /** The event with its items in title order, or null when it is not the owner's. */
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
        $items = $this->rows(
            'SELECT g.id, g.Title, g.MnP, g.MxP, g.SS, g.Age, g.MnT, g.MxT, g.is_kept, ei.setting, ei.note, ei.is_packed
             FROM event_items ei JOIN games g ON g.id = ei.artifact_id AND g.user_id = ?
             WHERE ei.event_id = ?
             ORDER BY g.Title ASC, g.id ASC',
            'ii', [$this->userId, $id]
        );
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            foreach (['MnP', 'MxP', 'Age', 'MnT', 'MxT'] as $field) {
                $item[$field] = $item[$field] === null ? null : (int) $item[$field];
            }
            $item['SS'] = (string) $item['SS'];
            $item['is_packed'] = (bool) $item['is_packed'];
            $item['is_kept'] = artifact_is_kept($item);
        }
        $event['items'] = with_item_tags($this->db, $items, $this->userId);
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
        $items = $this->rows(
            'SELECT g.id, g.Title, g.MnP, g.MxP, g.SS, g.Age, g.is_kept, COALESCE(t.objectType, g.type) AS type_name
             FROM games g
             LEFT JOIN types t ON t.id = g.type_id
             LEFT JOIN event_items ei ON ei.artifact_id = g.id AND ei.event_id = ?
             WHERE g.user_id = ? AND ei.artifact_id IS NULL
             ORDER BY g.Title ASC, g.id ASC',
            'ii', [$eventId, $this->userId]
        );
        return array_map(fn($item) => [
            'id' => (int) $item['id'],
            'Title' => $item['Title'],
            'facts' => items_list_play_facts($item),
            'is_kept' => artifact_is_kept($item),
            'is_game' => item_type_is_game($item['type_name']),
        ], $items);
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
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($id) => $id > 0)));
        if ($itemIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $mine = $this->rows(
            "SELECT id FROM games WHERE user_id = ? AND id IN ($placeholders)",
            str_repeat('i', count($itemIds) + 1), array_merge([$this->userId], $itemIds)
        );
        if (count($mine) !== count($itemIds)) {
            throw new InvalidArgumentException('Choose items from your own items in Keeplore.');
        }
        $added = 0;
        foreach ($itemIds as $itemId) {
            $stmt = $this->statement(
                'INSERT IGNORE INTO event_items (event_id, artifact_id) VALUES (?, ?)',
                'ii', [$eventId, $itemId]
            );
            $added += $stmt->affected_rows;
            $stmt->close();
        }
        return $added;
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
