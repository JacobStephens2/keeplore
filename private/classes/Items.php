<?php

/**
 * The owner's Items. Every read and write is scoped to the owner: another
 * owner's Item is never found and never changed.
 */
final class Items
{
    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** The owner's Item row with its type name, or null. */
    public function find(int $id): ?array
    {
        return $this->rows(
            'SELECT types.objectType AS type_name, games.*
             FROM games LEFT JOIN types ON types.id = games.type_id
             WHERE games.id = ? AND games.user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
    }

    /** Delete the Item with its tags and its Event plan entries. */
    public function delete(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->requireItem($id);
            $this->statement('DELETE FROM item_tags WHERE artifact_id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
            $this->statement(
                'DELETE event_items FROM event_items
                 JOIN games ON games.id = event_items.artifact_id AND games.user_id = ?
                 WHERE event_items.artifact_id = ?',
                'ii', [$this->userId, $id]
            )->close();
            $this->statement('DELETE FROM games WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    private function requireItem(int $id): void
    {
        if (!$this->rows('SELECT id FROM games WHERE id = ? AND user_id = ? FOR UPDATE', 'ii', [$id, $this->userId])) {
            throw new OutOfBoundsException('Item not found.');
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
