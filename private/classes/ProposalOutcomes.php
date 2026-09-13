<?php

/** Account-owned proposal records. This module never writes uses or legacy responses. */
final class ProposalOutcomes
{
    public const OUTCOMES = [
        'explicit_decline' => 'Explicit decline',
        'chose_something_else' => 'Chose something else',
    ];

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    public function save(array $input, ?int $id = null): int
    {
        $input = $this->validate($input);
        $this->requireItem($input['item_id']);
        $participantIds = $input['participant_ids'];
        if ($participantIds) {
            $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
            $owned = $this->rows("SELECT id FROM players WHERE user_id = ? AND id IN ($placeholders)",
                str_repeat('i', count($participantIds) + 1), array_merge([$this->userId], $participantIds));
            if (count($owned) !== count($participantIds)) {
                throw new InvalidArgumentException('Choose participants from your own people list.');
            }
        }
        $chosenId = $input['chosen_item_id'];
        $chosenName = $input['chosen_item_name'];
        if ($chosenId !== null) {
            $chosenName = $this->requireItem($chosenId)['Title'];
        }
        $this->db->begin_transaction();
        try {
            $params = [$input['item_id'], $input['proposal_date'], $input['outcome'],
                $input['note'], $chosenId, $chosenName, $this->userId];
            if ($id === null) {
                $stmt = $this->statement(
                    'INSERT INTO proposal_outcomes
                        (item_id, proposal_date, outcome, note, chosen_item_id, chosen_item_name, user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)', 'isssisi', $params
                );
                $id = (int) $this->db->insert_id;
            } else {
                if (!$this->rows('SELECT id FROM proposal_outcomes WHERE id = ? AND user_id = ? FOR UPDATE',
                    'ii', [$id, $this->userId])) {
                    throw new OutOfBoundsException('Proposal outcome not found.');
                }
                $stmt = $this->statement(
                    'UPDATE proposal_outcomes SET item_id = ?, proposal_date = ?, outcome = ?,
                        note = ?, chosen_item_id = ?, chosen_item_name = ? WHERE user_id = ? AND id = ?',
                    'isssisii', array_merge($params, [$id])
                );
                $this->statement('DELETE FROM proposal_outcome_players WHERE proposal_id = ?', 'i', [$id])->close();
            }
            $stmt->close();
            foreach ($participantIds as $playerId) {
                $this->statement(
                    'INSERT INTO proposal_outcome_players (proposal_id, player_id) VALUES (?, ?)',
                    'ii', [$id, $playerId]
                )->close();
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function find(int $id): ?array
    {
        return $this->withParticipants($this->rows(
            'SELECT * FROM proposal_outcomes WHERE id = ? AND user_id = ?',
            'ii', [$id, $this->userId]
        ))[0] ?? null;
    }

    public function history(int $itemId): array
    {
        return $this->withParticipants($this->rows(
            'SELECT * FROM proposal_outcomes WHERE item_id = ? AND user_id = ? ORDER BY proposal_date DESC, id DESC',
            'ii', [$itemId, $this->userId]
        ));
    }

    public function delete(int $id): void
    {
        $stmt = $this->statement('DELETE FROM proposal_outcomes WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId]);
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if ($deleted === 0) {
            throw new OutOfBoundsException('Proposal outcome not found.');
        }
    }

    public function report(string $start = '', string $end = '', bool $includeOtherItems = false,
        string $sort = 'explicit_declines', string $direction = 'desc'): array
    {
        if (!in_array($sort, ['item_name', 'explicit_declines', 'chose_something_else'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Choose a valid report sort.');
        }
        $dateClause = '';
        $params = [];
        $types = '';
        if ($start !== '') {
            $this->validateDate($start);
            $dateClause .= ' AND p.proposal_date >= ?';
            $params[] = $start;
            $types .= 's';
        }
        if ($end !== '') {
            $this->validateDate($end);
            $dateClause .= ' AND p.proposal_date <= ?';
            $params[] = $end;
            $types .= 's';
        }
        if ($start !== '' && $end !== '' && $start > $end) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }
        $collectionClause = $includeOtherItems ? '' : " AND (g.KeptCol = 1 OR g.InSecondaryCollection = 'yes')";
        $params[] = $this->userId;
        $types .= 'i';
        $rows = $this->rows(
            "SELECT g.id AS item_id, g.Title AS item_name, COALESCE(t.objectType, '') AS item_type,
                COALESCE(SUM(p.outcome = 'explicit_decline'), 0) AS explicit_declines,
                COALESCE(SUM(p.outcome = 'chose_something_else'), 0) AS chose_something_else
             FROM games g
             LEFT JOIN types t ON t.id = g.type_id
             LEFT JOIN proposal_outcomes p ON p.item_id = g.id AND p.user_id = g.user_id $dateClause
             WHERE g.user_id = ? $collectionClause
             GROUP BY g.id, g.Title, t.objectType
             ORDER BY $sort $direction, g.Title ASC, g.id ASC",
            $types, $params
        );
        foreach ($rows as &$row) {
            $row['explicit_declines'] = (int) $row['explicit_declines'];
            $row['chose_something_else'] = (int) $row['chose_something_else'];
        }
        return $rows;
    }

    private function withParticipants(array $records): array
    {
        if (!$records) {
            return [];
        }
        $ids = array_column($records, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $participants = $this->rows(
            "SELECT pp.proposal_id, p.id,
                TRIM(CONCAT(COALESCE(p.FirstName, ''), ' ', COALESCE(p.LastName, ''))) AS name
             FROM proposal_outcome_players pp JOIN players p ON p.id = pp.player_id
             WHERE p.user_id = ? AND pp.proposal_id IN ($placeholders) ORDER BY p.id",
            str_repeat('i', count($ids) + 1), array_merge([$this->userId], $ids)
        );
        $byProposal = [];
        foreach ($participants as $participant) {
            $byProposal[$participant['proposal_id']][] = ['id' => $participant['id'], 'name' => $participant['name']];
        }
        foreach ($records as &$record) {
            $record['participants'] = $byProposal[$record['id']] ?? [];
        }
        return $records;
    }

    private function validate(array $input): array
    {
        $input['item_id'] = $this->positiveId($input['item_id'] ?? null);
        foreach (['proposal_date', 'outcome', 'note', 'chosen_item_name'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Enter text for the date, outcome, note, and alternative name.');
            }
            $input[$field] = trim($value);
        }
        $this->validateDate($input['proposal_date']);
        if (!isset(self::OUTCOMES[$input['outcome']])) {
            throw new InvalidArgumentException('Choose an explicit decline or chose something else.');
        }
        if (strlen($input['note']) > 65535 || mb_strlen($input['chosen_item_name']) > 255) {
            throw new InvalidArgumentException('The note is too long, or the alternative name exceeds 255 characters.');
        }
        $chosenId = $input['chosen_item_id'] ?? null;
        $input['chosen_item_id'] = ($chosenId === '' || $chosenId === null) ? null : $this->positiveId($chosenId);
        if ($input['chosen_item_id'] === $input['item_id']) {
            throw new InvalidArgumentException('The item chosen instead must be different from the proposed item.');
        }
        $participants = $input['participant_ids'] ?? [];
        if (!is_array($participants)) {
            throw new InvalidArgumentException('Choose participants from your people list.');
        }
        $input['participant_ids'] = array_values(array_unique(array_map([$this, 'positiveId'], $participants)));
        return $input;
    }

    private function positiveId($value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Choose a valid item or participant.');
        }
        return $id;
    }

    private function validateDate(string $value): void
    {
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
        }
    }

    private function requireItem(int $id): array
    {
        $item = $this->rows('SELECT id, Title FROM games WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])[0] ?? null;
        if ($item === null) {
            throw new InvalidArgumentException('Choose an item from your own items.');
        }
        return $item;
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
