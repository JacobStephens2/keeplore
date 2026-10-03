<?php

/**
 * The owner's recorded uses: each an item used on a date, optionally with
 * people, a Setting and notes. Every read and write is scoped to the owner,
 * and every write checks the item and people belong to them.
 */
final class Uses
{
    public const MAX_COUNT = 20;

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
        $input = $this->validate($input);
        return $this->transaction(function () use ($input, $count) {
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
        $use = $this->rows(
            'SELECT uses.id, uses.artifact_id AS item_id, games.Title AS item_title, uses.use_date,
                uses.note AS setting, uses.notesTwo AS notes
             FROM uses LEFT JOIN games ON games.id = uses.artifact_id
             WHERE uses.id = ? AND uses.user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
        if ($use === null) {
            return null;
        }
        $use['id'] = (int) $use['id'];
        $use['item_id'] = (int) $use['item_id'];
        $use['item_title'] = (string) $use['item_title'];
        $use['use_date'] = substr((string) $use['use_date'], 0, 10);
        $use['setting'] = (string) $use['setting'];
        $use['notes'] = (string) $use['notes'];
        $use['people'] = array_map(fn (array $person) => [
            'id' => (int) $person['id'],
            'name' => $person['name'],
        ], $this->rows(
            "SELECT players.id, TRIM(CONCAT(COALESCE(players.FirstName, ''), ' ', COALESCE(players.LastName, ''))) AS name
             FROM uses_players JOIN players ON players.id = uses_players.player_id
             WHERE uses_players.use_id = ? AND uses_players.user_id = ?
             ORDER BY uses_players.id",
            'ii', [$id, $this->userId]
        ));
        return $use;
    }

    /** Replace the use's item, date, Setting, notes and people. */
    public function update(int $id, array $input): void
    {
        $input = $this->validate($input);
        $this->transaction(function () use ($id, $input) {
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

        $date = $input['use_date'] ?? '';
        if (!is_string($date) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
        }

        $text = [];
        foreach (['setting', 'notes'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Enter text for the Setting and notes.');
            }
            $text[$field] = $value;
        }

        $playerIds = $input['player_ids'] ?? [];
        if (!is_array($playerIds)) {
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
