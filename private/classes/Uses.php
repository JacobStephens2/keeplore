<?php

require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/People.php';

/**
 * The owner's recorded uses: each an item used on a date, optionally with
 * people, a Setting and notes. Every read and write is scoped to the owner,
 * and every write checks the item and people belong to them.
 */
final class Uses
{
    public const MAX_COUNT = 20;

    /** The rule that makes a legacy response a play: a play date set. */
    public const IS_PLAY = 'responses.PlayDate > 0';

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /**
     * Write the input's count of identical uses (1-20, missing means 1) and
     * return their ids in the order they were written.
     */
    public function record(array $input): array
    {
        $count = max(1, min(self::MAX_COUNT, (int) ($input['count'] ?? 1)));
        return $this->transaction(function () use ($input, $count) {
            $input = $this->validate($input);
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $this->statement(
                    'INSERT INTO uses (artifact_id, use_date, user_id, note, notesTwo) VALUES (?, ?, ?, ?, ?)',
                    'isiss', [$input['item_id'], $input['use_date'], $this->userId, $input['setting'], $input['notes']]
                )->close();
                $id = (int) $this->db->insert_id;
                $this->insertPeople($id, $input['player_ids']);
                $ids[] = $id;
            }
            return $ids;
        });
    }

    public function find(int $id): ?array
    {
        return $this->read('uses.id = ?', 'i', [$id])[0] ?? null;
    }

    /**
     * The owner's uses, newest first (by use date, then id), each as find()
     * reads it, including uses with no date.
     *
     * Filters combine with AND; null or missing means no filter. item_id
     * keeps that item's uses and person_id the uses that person took part
     * in. type_ids keeps the uses whose item has one of those types; []
     * lists nothing. since keeps uses on or after a YYYY-MM-DD date; blank
     * means no filter. An unknown key or a malformed since throws
     * InvalidArgumentException.
     */
    public function all(array $filters = []): array
    {
        $unknown = array_diff(array_keys($filters), ['item_id', 'person_id', 'type_ids', 'since']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Use list filter: ' . implode(', ', $unknown) . '.');
        }
        $typeIds = $filters['type_ids'] ?? null;
        if ($typeIds === []) {
            return [];
        }

        $where = ['TRUE'];
        $types = '';
        $params = [];
        if (isset($filters['item_id'])) {
            $where[] = 'uses.artifact_id = ?';
            $types .= 'i';
            $params[] = $filters['item_id'];
        }
        if (isset($filters['person_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM uses_players
                WHERE uses_players.use_id = uses.id AND uses_players.player_id = ? AND uses_players.user_id = uses.user_id)';
            $types .= 'i';
            $params[] = $filters['person_id'];
        }
        if ($typeIds !== null) {
            $where[] = 'games.type_id IN (' . implode(', ', array_fill(0, count($typeIds), '?')) . ')';
            $types .= str_repeat('s', count($typeIds));
            array_push($params, ...array_map('strval', array_values($typeIds)));
        }
        $since = $filters['since'] ?? '';
        if (!is_string($since) || trim($since) !== '') {
            $where[] = 'uses.use_date >= ?';
            $types .= 's';
            $params[] = $this->validDate($since);
        }
        return $this->read(implode(' AND ', $where), $types, $params);
    }

    /**
     * Each of the owner's items' Use count: its uses under Items' owner rule
     * plus the legacy plays of it the owner's own person took part in, on or
     * after the YYYY-MM-DD since date, or in all recorded history when it is
     * null. An owner with no person marked as themself counts uses only.
     * Each row is item_id, item_title, item_type (or null) and use_count,
     * highest count first, then by title; items with no use in the period
     * are left out. A malformed since throws InvalidArgumentException.
     */
    public function useCounts(?string $since = null): array
    {
        // With no person marked as the owner, Player = NULL matches no legacy play.
        $me = (new People($this->db, $this->userId))->me();
        $uses = ['owner_items.user_id = ?'];
        $plays = ['responses.user_id = ?', 'responses.Player = ?', self::IS_PLAY];
        $usesParams = [$this->userId];
        $playsParams = [$this->userId, $me];
        if ($since !== null) {
            $since = $this->validDate($since);
            $uses[] = 'uses.use_date >= ?';
            $plays[] = 'responses.PlayDate >= ?';
            $usesParams[] = $playsParams[] = $since;
        }
        $params = array_merge($usesParams, $playsParams, [$this->userId]);
        return array_map(fn (array $row) => [
            'item_id' => (int) $row['item_id'],
            'item_title' => (string) $row['item_title'],
            'item_type' => $row['item_type'] === null ? null : (string) $row['item_type'],
            'use_count' => (int) $row['use_count'],
        ], $this->rows(
            'SELECT games.id AS item_id, games.Title AS item_title, types.objectType AS item_type, COUNT(*) AS use_count
             FROM (SELECT uses.artifact_id AS item_id FROM ' . Items::OWNERS_USES . ' WHERE ' . implode(' AND ', $uses) . '
                UNION ALL SELECT responses.Title FROM responses WHERE ' . implode(' AND ', $plays) . ') counted
                JOIN games ON games.id = counted.item_id AND games.user_id = ?
                LEFT JOIN types ON types.id = games.type_id
             GROUP BY games.id, games.Title, types.objectType
             ORDER BY use_count DESC, games.Title, games.id',
            implode('', array_map(fn ($param) => is_string($param) ? 's' : 'i', $params)),
            $params
        ));
    }

    /**
     * The Setting of the owner's most recently recorded use (highest id),
     * or null when they have no use or that Setting is NULL.
     */
    public function lastSetting(): ?string
    {
        $row = $this->rows('SELECT note FROM uses WHERE user_id = ? ORDER BY id DESC LIMIT 1', 'i', [$this->userId])[0] ?? null;
        return $row === null || $row['note'] === null ? null : (string) $row['note'];
    }

    /** The owner's uses matching $where, newest first, each with its people. */
    private function read(string $where, string $types, array $params): array
    {
        $uses = array_map(fn (array $use) => [
            'id' => (int) $use['id'],
            'item_id' => (int) $use['item_id'],
            'item_title' => (string) $use['item_title'],
            'item_type' => $use['item_type'] === null ? null : (string) $use['item_type'],
            'use_date' => substr((string) $use['use_date'], 0, 10),
            'setting' => (string) $use['setting'],
            'notes' => (string) $use['notes'],
        ], $this->rows(
            "SELECT uses.id, uses.artifact_id AS item_id, games.Title AS item_title, types.objectType AS item_type,
                uses.use_date, uses.note AS setting, uses.notesTwo AS notes
             FROM uses
                LEFT JOIN games ON games.id = uses.artifact_id AND games.user_id = uses.user_id
                LEFT JOIN types ON types.id = games.type_id
             WHERE uses.user_id = ? AND $where
             ORDER BY uses.use_date DESC, uses.id DESC",
            'i' . $types, array_merge([$this->userId], $params)
        ));
        $people = $this->peopleOf(array_column($uses, 'id'));
        return array_map(fn (array $use) => $use + ['people' => $people[$use['id']] ?? []], $uses);
    }

    /** Each use's people, keyed by use id, in the order they were recorded. */
    private function peopleOf(array $useIds): array
    {
        if (!$useIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($useIds), '?'));
        $people = [];
        foreach ($this->rows(
            "SELECT uses_players.use_id, players.id, players.FirstName, players.LastName
             FROM uses_players JOIN players ON players.id = uses_players.player_id
             WHERE uses_players.use_id IN ($placeholders) AND uses_players.user_id = ? AND players.user_id = ?
             ORDER BY uses_players.id",
            str_repeat('i', count($useIds) + 2), array_merge($useIds, [$this->userId, $this->userId])
        ) as $person) {
            $firstName = (string) $person['FirstName'];
            $lastName = (string) $person['LastName'];
            $people[(int) $person['use_id']][] = [
                'id' => (int) $person['id'],
                'name' => trim($firstName . ' ' . $lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
        }
        return $people;
    }

    /** Replace the use's item, date, Setting, notes and people. */
    public function update(int $id, array $input): void
    {
        $this->transaction(function () use ($id, $input) {
            $input = $this->validate($input);
            $this->requireUse($id);
            $this->statement(
                'UPDATE uses SET artifact_id = ?, use_date = ?, note = ?, notesTwo = ? WHERE id = ? AND user_id = ?',
                'isssii', [$input['item_id'], $input['use_date'], $input['setting'], $input['notes'], $id, $this->userId]
            )->close();
            $this->statement('DELETE FROM uses_players WHERE use_id = ?', 'i', [$id])->close();
            $this->insertPeople($id, $input['player_ids']);
        });
    }

    public function delete(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->requireUse($id);
            $this->statement('DELETE FROM uses_players WHERE use_id = ?', 'i', [$id])->close();
            $this->statement('DELETE FROM uses WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    private function insertPeople(int $useId, array $playerIds): void
    {
        foreach ($playerIds as $playerId) {
            $this->statement(
                'INSERT INTO uses_players (use_id, player_id, user_id) VALUES (?, ?, ?)',
                'iii', [$useId, $playerId, $this->userId]
            )->close();
        }
    }

    private function validate(array $input): array
    {
        if (empty($input['item_id'])) {
            throw new InvalidArgumentException('Please choose an item.');
        }
        $itemId = filter_var($input['item_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($itemId === false
            || !$this->rows('SELECT id FROM games WHERE id = ? AND user_id = ?', 'ii', [$itemId, $this->userId])) {
            throw new InvalidArgumentException('Choose an item from your own items.');
        }

        $date = $this->validDate($input['use_date'] ?? '');

        $text = [];
        foreach (['setting', 'notes'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Enter text for the Setting and notes.');
            }
            $text[$field] = $value;
        }

        $playerIds = $input['player_ids'] ?? [];
        if (!is_array($playerIds) || array_filter($playerIds, fn ($playerId) => !is_scalar($playerId) && $playerId !== null)) {
            throw new InvalidArgumentException('Choose people from your own people list.');
        }
        $playerIds = array_values(array_unique(array_filter(array_map('intval', $playerIds))));
        if ($playerIds) {
            $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
            $owned = $this->rows("SELECT id FROM players WHERE user_id = ? AND id IN ($placeholders)",
                str_repeat('i', count($playerIds) + 1), array_merge([$this->userId], $playerIds));
            if (count($owned) !== count($playerIds)) {
                throw new InvalidArgumentException('Choose people from your own people list.');
            }
        }

        return [
            'item_id' => $itemId,
            'use_date' => $date,
            'player_ids' => $playerIds,
        ] + $text;
    }

    private function validDate(mixed $date): string
    {
        if (!is_string($date) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
        }
        return $date;
    }

    private function requireUse(int $id): void
    {
        if (!$this->rows('SELECT id FROM uses WHERE id = ? AND user_id = ? FOR UPDATE', 'ii', [$id, $this->userId])) {
            throw new OutOfBoundsException('Use not found.');
        }
    }

    private function transaction(callable $work): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
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
