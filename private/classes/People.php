<?php

/**
 * The owner's people list: everyone who can be recorded on uses, item
 * proposals and events, one of whom can be marked as the owner themself.
 * Every read and write is scoped to the owner.
 */
final class People
{
    private const COLUMNS = 'id, FirstName, LastName, G, birth_year, represents_user_id';

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

    public function find(int $id): ?array
    {
        $row = $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM players WHERE id = ? AND user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
        return $row === null ? null : $this->person($row);
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

    /** Remove the person from every use, proposal, event and playgroup slot, then delete them. */
    public function delete(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->requirePerson($id);
            $this->statement('DELETE FROM uses_players WHERE player_id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
            $this->statement(
                'DELETE pop FROM proposal_outcome_players AS pop
                 JOIN proposal_outcomes AS po ON po.id = pop.proposal_id AND po.user_id = ?
                 WHERE pop.player_id = ?',
                'ii', [$this->userId, $id]
            )->close();
            $this->statement(
                'DELETE ep FROM event_players AS ep
                 JOIN events AS e ON e.id = ep.event_id AND e.user_id = ?
                 WHERE ep.player_id = ?',
                'ii', [$this->userId, $id]
            )->close();
            $this->statement('DELETE FROM playgroup WHERE FullName = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
            $this->unlinkAccountFrom($id);
            $this->statement('DELETE FROM players WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    /**
     * Move everything recorded with the loser to the survivor, then delete
     * the loser. Where both were on the same use, proposal or event, the
     * loser's link is dropped. The person who is the owner can only survive.
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
            $accountIsLoser = (bool) $this->rows('SELECT id FROM users WHERE id = ? AND player_id = ?', 'ii', [$this->userId, $loserId]);
            if (($isMe[$loserId] && !$isMe[$survivorId]) || $accountIsLoser) {
                throw new InvalidArgumentException('The surviving player must be the one marked as you.');
            }

            $this->statement(
                'DELETE loser FROM uses_players AS loser
                 JOIN uses_players AS survivor ON survivor.use_id = loser.use_id AND survivor.player_id = ?
                 WHERE loser.player_id = ? AND loser.user_id = ?',
                'iii', [$survivorId, $loserId, $this->userId]
            )->close();
            $this->statement(
                'UPDATE uses_players SET player_id = ? WHERE player_id = ? AND user_id = ?',
                'iii', [$survivorId, $loserId, $this->userId]
            )->close();
            $this->statement(
                'DELETE loser FROM proposal_outcome_players AS loser
                 JOIN proposal_outcome_players AS survivor ON survivor.proposal_id = loser.proposal_id AND survivor.player_id = ?
                 JOIN proposal_outcomes AS po ON po.id = loser.proposal_id AND po.user_id = ?
                 WHERE loser.player_id = ?',
                'iii', [$survivorId, $this->userId, $loserId]
            )->close();
            $this->statement(
                'UPDATE proposal_outcome_players AS pop
                 JOIN proposal_outcomes AS po ON po.id = pop.proposal_id AND po.user_id = ?
                 SET pop.player_id = ? WHERE pop.player_id = ?',
                'iii', [$this->userId, $survivorId, $loserId]
            )->close();
            $this->statement(
                'DELETE loser FROM event_players AS loser
                 JOIN event_players AS survivor ON survivor.event_id = loser.event_id AND survivor.player_id = ?
                 JOIN events AS e ON e.id = loser.event_id AND e.user_id = ?
                 WHERE loser.player_id = ?',
                'iii', [$survivorId, $this->userId, $loserId]
            )->close();
            $this->statement(
                'UPDATE event_players AS ep
                 JOIN events AS e ON e.id = ep.event_id AND e.user_id = ?
                 SET ep.player_id = ? WHERE ep.player_id = ?',
                'iii', [$this->userId, $survivorId, $loserId]
            )->close();
            $this->statement(
                'UPDATE playgroup SET FullName = ? WHERE FullName = ? AND user_id = ?',
                'iii', [$survivorId, $loserId, $this->userId]
            )->close();
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
