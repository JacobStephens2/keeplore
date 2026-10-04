<?php

require_once __DIR__ . '/Aversions.php';
require_once __DIR__ . '/Uses.php';

/**
 * The owner's people list: everyone who can be recorded on uses, item
 * proposals and events, one of whom can be marked as the owner themself.
 * Every read and write is scoped to the owner: another owner's person is
 * never found and never changed.
 *
 * A person is an array of id, first_name, last_name, name (first and last
 * joined), gender, birth_year (int or null) and is_me. Input for create and
 * update uses the same names, with is_me read only by update.
 *
 * Invalid input, and a merge its guardrails refuse, throws
 * InvalidArgumentException; update or delete of a person the owner doesn't
 * have throws OutOfBoundsException. Nothing is written either way.
 */
final class People
{
    private const COLUMNS = 'id, FirstName, LastName, G, birth_year, represents_user_id';

    /**
     * Everything that points at a Person, the one list delete and merge both
     * read: a new table with a Person column belongs here. Rows are matched
     * by Person id alone once the owner holds the Person's lock; Person ids
     * are unique across owners.
     *
     * Delete deletes each table's rows for the Person. An entry with
     * 'deleted_where' deletes only the rows matching it; the rest keep their
     * occasion and lose the Person and the 'cleared' columns. Merge moves
     * the rows to the survivor, except that where the survivor already has
     * a row for the same 'occasion' (these columns equal, nulls counting as
     * equal), the merged Person's row is deleted.
     */
    private const POINTING_AT_PERSON = [
        ['table' => 'uses_players', 'column' => 'player_id', 'occasion' => ['use_id']],
        ['table' => 'proposal_outcome_players', 'column' => 'player_id', 'occasion' => ['proposal_id']],
        ['table' => 'event_players', 'column' => 'player_id', 'occasion' => ['event_id']],
        // Every Playgroup slot is the owner's, so any survivor slot collides.
        ['table' => 'playgroup', 'column' => 'FullName', 'occasion' => ['user_id']],
        // Legacy plays and Aversions: delete deletes the Aversions and keeps
        // the plays, a play that was also an Aversion losing its aversion date.
        [
            'table' => 'responses', 'column' => 'Player', 'occasion' => ['Title', 'PlayDate', 'AversionDate'],
            'deleted_where' => Aversions::IS_AVERSION . ' AND (' . Uses::IS_PLAY . ') IS NOT TRUE',
            'cleared' => ['AversionDate'],
        ],
    ];

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /** The owner's people in name order. */
    public function all(): array
    {
        return array_map([$this, 'person'], $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM players WHERE user_id = ? ORDER BY FirstName, LastName, id',
            'i', [$this->userId]
        ));
    }

    /**
     * The owner's people whose current name contains the query, ignoring
     * case and matching % and _ literally, in name order. A blank query
     * returns everyone.
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->all();
        }
        $pattern = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        // The name matched is person()'s name: first and last joined, trimmed.
        return array_map([$this, 'person'], $this->rows(
            'SELECT ' . self::COLUMNS . " FROM players
             WHERE user_id = ?
               AND LOWER(TRIM(CONCAT(COALESCE(FirstName, ''), ' ', COALESCE(LastName, '')))) LIKE LOWER(?) ESCAPE '!'
             ORDER BY FirstName, LastName, id",
            'is', [$this->userId, $pattern]
        ));
    }

    /** The owner's person with this id, or null if the owner has none. */
    public function find(int $id): ?array
    {
        $row = $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM players WHERE id = ? AND user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
        return $row === null ? null : $this->person($row);
    }

    /**
     * The id of the owner's person marked as them, or null. When the account
     * has no link but one of the owner's people represents it, the account
     * links to them first.
     */
    public function me(): ?int
    {
        $link = (int) ($this->rows('SELECT player_id FROM users WHERE id = ?', 'i', [$this->userId])[0]['player_id'] ?? 0);
        if ($link !== 0) {
            return $link;
        }
        $id = $this->rows(
            'SELECT id FROM players WHERE represents_user_id = ? AND user_id = ? ORDER BY id LIMIT 1',
            'ii', [$this->userId, $this->userId]
        )[0]['id'] ?? null;
        if ($id === null) {
            return null;
        }
        $this->statement(
            'UPDATE users SET player_id = ? WHERE id = ? AND (player_id IS NULL OR player_id = 0)',
            'ii', [$id, $this->userId]
        )->close();
        return (int) $id;
    }

    /** Add a person to the owner's list and return their id. */
    public function create(array $input): int
    {
        $input = $this->validate($input);
        $this->statement(
            'INSERT INTO players (FirstName, LastName, FullName, G, birth_year, user_id) VALUES (?, ?, ?, ?, ?, ?)',
            'ssssii', [$input['first_name'], $input['last_name'], $input['name'], $input['gender'], $input['birth_year'], $this->userId]
        )->close();
        return (int) $this->db->insert_id;
    }

    /**
     * Replace the person's names, gender and birth year. With is_me, they
     * become the one person marked as the owner and the account links to
     * them; without it, the mark and link are removed only if they were theirs.
     */
    public function update(int $id, array $input): void
    {
        $this->transaction(function () use ($id, $input) {
            $isMe = (bool) ($input['is_me'] ?? false);
            $input = $this->validate($input);
            $this->requirePerson($id);
            $this->statement(
                'UPDATE players SET FirstName = ?, LastName = ?, FullName = ?, G = ?, birth_year = ? WHERE id = ? AND user_id = ?',
                'ssssiii', [$input['first_name'], $input['last_name'], $input['name'], $input['gender'], $input['birth_year'], $id, $this->userId]
            )->close();
            if ($isMe) {
                $this->statement(
                    'UPDATE players SET represents_user_id = IF(id = ?, ?, NULL)
                     WHERE user_id = ? AND (id = ? OR represents_user_id = ?)',
                    'iiiii', [$id, $this->userId, $this->userId, $id, $this->userId]
                )->close();
                $this->statement('UPDATE users SET player_id = ? WHERE id = ?', 'ii', [$id, $this->userId])->close();
            } else {
                $this->statement(
                    'UPDATE players SET represents_user_id = NULL WHERE id = ? AND user_id = ?',
                    'ii', [$id, $this->userId]
                )->close();
                $this->unlinkAccountFrom($id);
            }
        });
    }

    /**
     * Remove the person from every use, proposal, event and playgroup slot,
     * delete their Aversions and keep their legacy plays without them, then
     * delete them.
     */
    public function delete(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->requirePerson($id);
            foreach (self::POINTING_AT_PERSON as $reference) {
                ['table' => $table, 'column' => $column] = $reference;
                if (!isset($reference['deleted_where'])) {
                    $this->statement("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$id])->close();
                    continue;
                }
                $this->statement("DELETE FROM {$table} WHERE {$column} = ? AND {$reference['deleted_where']}", 'i', [$id])->close();
                $cleared = implode('', array_map(fn (string $cleared) => ", {$cleared} = NULL", $reference['cleared']));
                $this->statement("UPDATE {$table} SET {$column} = NULL{$cleared} WHERE {$column} = ?", 'i', [$id])->close();
            }
            $this->unlinkAccountFrom($id);
            $this->statement('DELETE FROM players WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    /**
     * Move everything recorded with the loser to the survivor, then delete
     * the loser. Where the survivor already has a record for the same
     * occasion (the same use, proposal or event, any playgroup slot, or a
     * legacy play or Aversion of the same item on the same dates), the
     * loser's is dropped. The person who is the owner can only survive.
     */
    public function merge(int $survivorId, int $loserId): void
    {
        $this->transaction(function () use ($survivorId, $loserId) {
            $isMe = [];
            foreach ($this->rows(
                'SELECT id, represents_user_id FROM players WHERE id IN (?, ?) AND user_id = ? FOR UPDATE',
                'iii', [$survivorId, $loserId, $this->userId]
            ) as $row) {
                $isMe[(int) $row['id']] = (int) $row['represents_user_id'] === $this->userId;
            }
            if (!isset($isMe[$survivorId], $isMe[$loserId])) {
                throw new InvalidArgumentException('Both players must exist.');
            }
            if ($survivorId === $loserId) {
                throw new InvalidArgumentException('Cannot merge a player into itself.');
            }
            $accountLinksToLoser = (bool) $this->rows('SELECT id FROM users WHERE id = ? AND player_id = ?', 'ii', [$this->userId, $loserId]);
            if (($isMe[$loserId] && !$isMe[$survivorId]) || $accountLinksToLoser) {
                throw new InvalidArgumentException('The surviving player must be the one marked as you.');
            }

            foreach (self::POINTING_AT_PERSON as ['table' => $table, 'column' => $column, 'occasion' => $occasion]) {
                $sameOccasion = implode(' AND ', array_map(fn (string $match) => "survivor.{$match} <=> loser.{$match}", $occasion));
                $this->statement(
                    "DELETE loser FROM {$table} AS loser
                     JOIN {$table} AS survivor ON survivor.{$column} = ? AND {$sameOccasion}
                     WHERE loser.{$column} = ?",
                    'ii', [$survivorId, $loserId]
                )->close();
                $this->statement("UPDATE {$table} SET {$column} = ? WHERE {$column} = ?", 'ii', [$survivorId, $loserId])->close();
            }
            $this->statement('DELETE FROM players WHERE id = ? AND user_id = ?', 'ii', [$loserId, $this->userId])->close();
        });
    }

    private function unlinkAccountFrom(int $id): void
    {
        $this->statement('UPDATE users SET player_id = NULL WHERE id = ? AND player_id = ?', 'ii', [$this->userId, $id])->close();
    }

    private function requirePerson(int $id): void
    {
        if (!$this->rows('SELECT id FROM players WHERE id = ? AND user_id = ? FOR UPDATE', 'ii', [$id, $this->userId])) {
            throw new OutOfBoundsException('Person not found.');
        }
    }

    private function validate(array $input): array
    {
        $names = [];
        foreach (['first_name', 'last_name'] as $field) {
            $value = $input[$field] ?? '';
            $names[$field] = is_scalar($value) ? trim((string) $value) : '';
        }
        if ($names['first_name'] === '' && $names['last_name'] === '') {
            throw new InvalidArgumentException('Please enter a name.');
        }

        $gender = $input['gender'] ?? '';
        $gender = is_scalar($gender) ? trim((string) $gender) : '';

        $birthYear = $input['birth_year'] ?? '';
        if (is_string($birthYear)) {
            $birthYear = trim($birthYear);
        }
        if ($birthYear === '' || $birthYear === null) {
            $birthYear = null;
        } else {
            $birthYear = filter_var($birthYear, FILTER_VALIDATE_INT, ['options' => [
                'min_range' => 1900,
                'max_range' => (int) date('Y') + 1,
            ]]);
            if ($birthYear === false) {
                throw new InvalidArgumentException('Please enter a real birth year.');
            }
        }

        return $names + [
            'name' => trim($names['first_name'] . ' ' . $names['last_name']),
            'gender' => $gender === '' ? 'other' : $gender,
            'birth_year' => $birthYear,
        ];
    }

    private function person(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['FirstName'],
            'last_name' => (string) $row['LastName'],
            'name' => trim($row['FirstName'] . ' ' . $row['LastName']),
            'gender' => (string) $row['G'],
            'birth_year' => $row['birth_year'] === null ? null : (int) $row['birth_year'],
            'is_me' => (int) $row['represents_user_id'] === $this->userId,
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
