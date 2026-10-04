<?php

/**
 * The owner's Aversions: legacy records, from before item proposals, that a
 * person was averse to an item on a date. An Aversion is a row of the legacy
 * responses table whose aversion date is set (IS_AVERSION); rows holding only
 * a play are not Aversions and are never found or changed here. Every read
 * and write is scoped to the owner, and an Aversion can only name the
 * owner's own items and people.
 *
 * An Aversion is an array of id, item_id, item (the item's title), person_id,
 * person (first and last name joined) and date. The item and person are
 * blank when they are not the owner's.
 *
 * An item or person that isn't the owner's, a date that isn't a calendar
 * date, or a record with nobody chosen throws InvalidArgumentException; an
 * Aversion the owner doesn't have throws OutOfBoundsException. Nothing is
 * written either way.
 */
final class Aversions
{
    /** The rule that makes a legacy response an Aversion. */
    public const IS_AVERSION = 'responses.AversionDate > 0';

    private const AVERSIONS = 'SELECT responses.id, responses.Title, games.Title AS item, responses.Player,
          players.FirstName, players.LastName, responses.AversionDate
        FROM responses
        LEFT JOIN games ON games.id = responses.Title AND games.user_id = responses.user_id
        LEFT JOIN players ON players.id = responses.Player AND players.user_id = responses.user_id
        WHERE responses.user_id = ? AND ' . self::IS_AVERSION;

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** The owner's Aversions, newest aversion date first. */
    public function all(): array
    {
        return array_map([$this, 'aversion'], $this->rows(
            self::AVERSIONS . ' ORDER BY responses.AversionDate DESC, games.Title DESC, players.LastName, players.FirstName, responses.id',
            'i', [$this->userId]
        ));
    }

    /** The owner's Aversion with this id, or null if the owner has none. */
    public function find(int $id): ?array
    {
        $row = $this->rows(self::AVERSIONS . ' AND responses.id = ?', 'ii', [$this->userId, $id])[0] ?? null;
        return $row === null ? null : $this->aversion($row);
    }

    /**
     * Record one Aversion of the item on the date per chosen person, all or
     * none. Blank choices, as from Record Aversion's unused selects, are
     * skipped.
     */
    public function record(int $itemId, string $date, array $personIds): void
    {
        $chosen = array_values(array_filter($personIds, fn ($id) => trim((string) $id) !== ''));
        if ($chosen === []) {
            throw new InvalidArgumentException('Choose a person who was averse to the item.');
        }
        $this->transaction(function () use ($itemId, $date, $chosen) {
            $date = $this->validDate($date);
            $itemId = $this->ownItem($itemId);
            $ids = array_map([$this, 'ownPerson'], $chosen);
            foreach ($ids as $id) {
                $this->statement(
                    'INSERT INTO responses (Title, AversionDate, Player, user_id) VALUES (?, ?, ?, ?)',
                    'isii', [$itemId, $date, $id, $this->userId]
                )->close();
            }
        });
    }

    /** Change the Aversion's item, person and date. Its play date and note stay as they are. */
    public function update(int $id, int $itemId, int $personId, string $date): void
    {
        $this->transaction(function () use ($id, $itemId, $personId, $date) {
            $this->requireAversion($id);
            $this->statement(
                'UPDATE responses SET Title = ?, Player = ?, AversionDate = ? WHERE id = ? AND user_id = ?',
                'iisii', [$this->ownItem($itemId), $this->ownPerson($personId), $this->validDate($date), $id, $this->userId]
            )->close();
        });
    }

    /** Delete the owner's Aversion. */
    public function remove(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->requireAversion($id);
            $this->statement('DELETE FROM responses WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    private function requireAversion(int $id): void
    {
        if (!$this->rows(
            'SELECT id FROM responses WHERE id = ? AND user_id = ? AND ' . self::IS_AVERSION . ' FOR UPDATE',
            'ii', [$id, $this->userId]
        )) {
            throw new OutOfBoundsException('Aversion not found.');
        }
    }

    private function ownItem(int $itemId): int
    {
        if (!$this->rows('SELECT id FROM games WHERE id = ? AND user_id = ?', 'ii', [$itemId, $this->userId])) {
            throw new InvalidArgumentException('Choose an item from your own items.');
        }
        return $itemId;
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

    private function validDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Please enter a real aversion date.');
        }
        return $date;
    }

    private function aversion(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'item_id' => (int) $row['Title'],
            'item' => (string) $row['item'],
            'person_id' => $row['Player'] === null ? null : (int) $row['Player'],
            'person' => trim($row['FirstName'] . ' ' . $row['LastName']),
            'date' => (string) $row['AversionDate'],
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
