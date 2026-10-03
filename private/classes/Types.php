<?php

/**
 * The owner's types: their own categories for items, such as table game,
 * book or film. Each item has at most one, by type_id, with its name cached
 * in the item's type column. Every read and write is scoped to the owner:
 * another owner's type is never found and never changed.
 *
 * A type is an array of id, name, kept_count and not_kept_count, the counts
 * being the owner's items with the type that are kept and that are not.
 *
 * A bad name, or a delete destination that is not another of the owner's
 * types, throws InvalidArgumentException; rename or delete of a type the
 * owner doesn't have throws OutOfBoundsException. Nothing is written either
 * way.
 */
final class Types
{
    private const NAME_LENGTH = 100;

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** The owner's types in name order. */
    public function all(): array
    {
        return $this->select('', 'i', [$this->userId]);
    }

    /** The owner's type with this id, or null if the owner has none. */
    public function find(int $id): ?array
    {
        return $this->select('AND types.id = ?', 'ii', [$this->userId, $id])[0] ?? null;
    }

    /** Add a type to the owner's list and return its id. */
    public function create(string $name): int
    {
        return $this->transaction(function () use ($name) {
            $name = $this->validName($name, null);
            $this->statement('INSERT INTO types (objectType, user_id) VALUES (?, ?)', 'si', [$name, $this->userId])->close();
            return (int) $this->db->insert_id;
        });
    }

    /** Rename the type, and every one of the owner's items with it, together. */
    public function rename(int $id, string $name): void
    {
        $this->transaction(function () use ($id, $name) {
            $this->requireType($id);
            $name = $this->validName($name, $id);
            $this->statement('UPDATE types SET objectType = ? WHERE id = ? AND user_id = ?', 'sii', [$name, $id, $this->userId])->close();
            $this->statement('UPDATE games SET type = ? WHERE type_id = ? AND user_id = ?', 'sii', [$name, $id, $this->userId])->close();
        });
    }

    /**
     * Move the owner's items with the type to $moveItemsTo, or leave them
     * without a type when it is null, then delete the type. The owner's Type
     * for BoardGameGeek items follows the items when it was this type.
     */
    public function delete(int $id, ?int $moveItemsTo): void
    {
        $this->transaction(function () use ($id, $moveItemsTo) {
            $this->requireType($id);
            $destination = null;
            if ($moveItemsTo !== null) {
                $destination = $moveItemsTo === $id ? null : $this->lockedName($moveItemsTo);
                if ($destination === null) {
                    throw new InvalidArgumentException('Please choose another of your types to move the items to.');
                }
            }
            $this->statement(
                'UPDATE games SET type_id = ?, type = ? WHERE type_id = ? AND user_id = ?',
                'isii', [$moveItemsTo, $destination, $id, $this->userId]
            )->close();
            $this->statement(
                'UPDATE users SET bgg_default_type_id = ? WHERE id = ? AND bgg_default_type_id = ?',
                'iii', [$moveItemsTo, $this->userId, $id]
            )->close();
            $this->statement('DELETE FROM types WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    private function requireType(int $id): void
    {
        if ($this->lockedName($id) === null) {
            throw new OutOfBoundsException('Type not found.');
        }
    }

    /** The name of the owner's type with this id, locked for the transaction, or null if the owner has none. */
    private function lockedName(int $id): ?string
    {
        $row = $this->rows('SELECT objectType FROM types WHERE id = ? AND user_id = ? FOR UPDATE', 'ii', [$id, $this->userId])[0] ?? null;
        return $row === null ? null : (string) $row['objectType'];
    }

    /**
     * The name trimmed, or InvalidArgumentException when it is blank, too
     * long for the column, or another of the owner's type names in any case.
     * $id is the type being renamed, which may keep its own name. Locks the
     * owner's types so a concurrent create or rename can't take the name.
     */
    private function validName(string $name, ?int $id): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Please enter a type name.');
        }
        if (mb_strlen($name) > self::NAME_LENGTH) {
            throw new InvalidArgumentException('A type name can be at most ' . self::NAME_LENGTH . ' characters.');
        }
        foreach ($this->rows('SELECT id, objectType FROM types WHERE user_id = ? FOR UPDATE', 'i', [$this->userId]) as $row) {
            if ((int) $row['id'] !== $id && mb_strtolower((string) $row['objectType']) === mb_strtolower($name)) {
                throw new InvalidArgumentException('You already have a type with this name.');
            }
        }
        return $name;
    }

    /** The owner's types, narrowed by $where, each with its kept and not-kept counts. */
    private function select(string $where, string $bindTypes, array $params): array
    {
        return array_map([$this, 'type'], $this->rows(
            "SELECT types.id, types.objectType,
                    COUNT(CASE WHEN games.is_kept = 1 THEN 1 END) AS kept_count,
                    COUNT(CASE WHEN games.is_kept = 0 THEN 1 END) AS not_kept_count
             FROM types
             LEFT JOIN games ON games.type_id = types.id AND games.user_id = types.user_id
             WHERE types.user_id = ? $where
             GROUP BY types.id, types.objectType
             ORDER BY types.objectType, types.id",
            $bindTypes, $params
        ));
    }

    private function type(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['objectType'],
            'kept_count' => (int) $row['kept_count'],
            'not_kept_count' => (int) $row['not_kept_count'],
        ];
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
