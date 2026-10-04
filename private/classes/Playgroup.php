<?php

/**
 * The owner's playgroup: the people from their people list whom they are
 * choosing items for on Choose for group. Every read and write is scoped to
 * the owner: another owner's slot is never found and never changed, and
 * only the owner's own people can fill a slot. A person can be in several
 * slots; the playgroup's size counts them once.
 *
 * A member is an array of id (the slot), person_id and name (first and last
 * joined).
 *
 * A person not on the owner's people list, or an add with nobody chosen,
 * throws InvalidArgumentException; a slot the owner doesn't have throws
 * OutOfBoundsException. Nothing is written either way.
 */
final class Playgroup
{
    private const MEMBERS = 'SELECT playgroup.ID, playgroup.FullName, players.FirstName, players.LastName
        FROM playgroup
        LEFT JOIN players ON players.id = playgroup.FullName AND players.user_id = playgroup.user_id
        WHERE playgroup.user_id = ?';

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** The owner's slots in the order they were added. */
    public function members(): array
    {
        return array_map([$this, 'slot'], $this->rows(self::MEMBERS . ' ORDER BY playgroup.ID', 'i', [$this->userId]));
    }

    /** The owner's slot with this id, or null if the owner has none. */
    public function member(int $slotId): ?array
    {
        $row = $this->rows(self::MEMBERS . ' AND playgroup.ID = ?', 'ii', [$this->userId, $slotId])[0] ?? null;
        return $row === null ? null : $this->slot($row);
    }

    /**
     * Put each chosen person in a new slot, all or none. Blank choices, as
     * from Add to group's unused selects, are skipped.
     */
    public function add(array $personIds): void
    {
        $chosen = array_values(array_filter($personIds, fn ($id) => trim((string) $id) !== ''));
        if ($chosen === []) {
            throw new InvalidArgumentException('Choose a person to add to the playgroup.');
        }
        $this->transaction(function () use ($chosen) {
            $ids = array_map([$this, 'ownPerson'], $chosen);
            foreach ($ids as $id) {
                $this->statement('INSERT INTO playgroup (FullName, user_id) VALUES (?, ?)', 'ii', [$id, $this->userId])->close();
            }
        });
    }

    /** Put another of the owner's people in the slot. */
    public function replace(int $slotId, int $personId): void
    {
        $this->transaction(function () use ($slotId, $personId) {
            $this->requireSlot($slotId);
            $this->statement(
                'UPDATE playgroup SET FullName = ? WHERE ID = ? AND user_id = ?',
                'iii', [$this->ownPerson($personId), $slotId, $this->userId]
            )->close();
        });
    }

    public function remove(int $slotId): void
    {
        $this->transaction(function () use ($slotId) {
            $this->requireSlot($slotId);
            $this->statement('DELETE FROM playgroup WHERE ID = ? AND user_id = ?', 'ii', [$slotId, $this->userId])->close();
        });
    }

    /**
     * Choose for group's rows: one per item of the Types in $typeIds (none
     * when it is empty) and member who has a legacy play or aversion date for
     * it, with their latest play, aversion, pass and request dates. With
     * $matchGroupSize, only items whose player counts include the
     * playgroup's size; with $keptOnly, only kept items.
     *
     * Each row keeps the legacy page's columns: the item's title, id, ss,
     * MnP, MxP, MxT, Age, type and is_kept; the member's FullName (their
     * person id), PlayerID, FirstName, LastName, G and Priority; and
     * MaxOfAversionDate, MaxOfPlayDate, MaxOfPassDate and MaxOfRequestDate.
     * AversionID is the id of one of the member's Aversions of the item (the
     * highest), or null when they have none. Rows are in the member's G and
     * Priority order, then oldest aversion and newest play first.
     */
    public function choose(array $typeIds, bool $matchGroupSize, bool $keptOnly): array
    {
        if ($typeIds === []) {
            return [];
        }
        $sql = 'SELECT
              games.title, games.id, games.ss, games.MnP, games.MxP, games.MxT, games.Age, games.type, games.is_kept,
              playgroup.FullName,
              players.id AS PlayerID, players.FirstName, players.LastName, players.G, players.Priority,
              MAX(CASE WHEN ' . Aversions::IS_AVERSION . ' THEN responses.id END) AS AversionID,
              MAX(responses.AversionDate) AS MaxOfAversionDate,
              MAX(responses.PlayDate) AS MaxOfPlayDate,
              MAX(responses.PassDate) AS MaxOfPassDate,
              MAX(responses.RequestDate) AS MaxOfRequestDate
            FROM playgroup
            JOIN players ON players.id = playgroup.FullName AND players.user_id = playgroup.user_id
            JOIN responses ON responses.Player = players.id AND responses.user_id = playgroup.user_id
            JOIN games ON games.id = responses.Title AND games.user_id = playgroup.user_id
            WHERE playgroup.user_id = ?
              AND games.type_id IN (' . implode(',', array_fill(0, count($typeIds), '?')) . ')';
        $types = 'i' . str_repeat('i', count($typeIds));
        $params = array_merge([$this->userId], array_map('intval', array_values($typeIds)));
        if ($matchGroupSize) {
            $size = $this->size();
            $sql .= ' AND games.MnP <= ? AND games.MxP >= ?';
            $types .= 'ii';
            array_push($params, $size, $size);
        }
        if ($keptOnly) {
            $sql .= ' AND games.is_kept = 1';
        }
        $sql .= ' GROUP BY games.id, players.id, playgroup.FullName
            HAVING MaxOfPlayDate IS NOT NULL OR MaxOfAversionDate IS NOT NULL
            ORDER BY
              players.G,
              players.Priority DESC,
              MAX(responses.AversionDate) ASC,
              MAX(responses.PlayDate) DESC,
              MAX(responses.PassDate) ASC,
              MAX(responses.RequestDate) DESC';
        return $this->rows($sql, $types, $params);
    }

    /** The number of distinct people from the owner's list in their playgroup. */
    private function size(): int
    {
        return (int) $this->rows(
            'SELECT COUNT(DISTINCT playgroup.FullName) AS size FROM playgroup
             JOIN players ON players.id = playgroup.FullName AND players.user_id = playgroup.user_id
             WHERE playgroup.user_id = ?',
            'i', [$this->userId]
        )[0]['size'];
    }

    private function requireSlot(int $slotId): void
    {
        if (!$this->rows('SELECT ID FROM playgroup WHERE ID = ? AND user_id = ? FOR UPDATE', 'ii', [$slotId, $this->userId])) {
            throw new OutOfBoundsException('Playgroup member not found.');
        }
    }

    /** The id of the owner's person this choice names. */
    private function ownPerson(mixed $choice): int
    {
        $id = is_scalar($choice) ? filter_var(trim((string) $choice), FILTER_VALIDATE_INT) : false;
        if ($id === false || !$this->rows('SELECT id FROM players WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])) {
            throw new InvalidArgumentException('Choose people from your own people list.');
        }
        return $id;
    }

    private function slot(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'person_id' => (int) $row['FullName'],
            'name' => trim($row['FirstName'] . ' ' . $row['LastName']),
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
