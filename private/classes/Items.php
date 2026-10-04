<?php

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/kept_status.php';
require_once dirname(__DIR__) . '/item_tags.php';
require_once dirname(__DIR__) . '/item_types.php';
require_once __DIR__ . '/Preferences.php';

/** An Item write's input broke the item rules; $errors lists every problem. */
final class ItemInvalid extends InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

/**
 * The owner's Items. Every read and write is scoped to the owner: another
 * owner's Item is never found and never changed.
 *
 * Input keys are the item's column names, with type_id for the type and
 * tags (a comma-separated string or a list) for the tags. Unknown keys are
 * ignored. Create and update apply the same validation and normalizers,
 * and write the Item with its tags in one transaction. The kept, to get
 * rid of and snooze writes each change only their own field.
 *
 * Invalid input throws ItemInvalid with every problem; an id the owner
 * doesn't have throws OutOfBoundsException.
 */
final class Items
{
    /**
     * A derived table of artifact_id and last_use, the only definition of an
     * Item's last use: the later of its latest recorded Use date and its
     * latest legacy play date, as Y-m-d. An Item with neither has no row.
     * Only Uses recorded by the Item's owner count. Join it on
     * artifact_id = games.id; it isn't owner-scoped, so the query joining it
     * must scope games to the owner.
     */
    public const LAST_USE = "(SELECT artifact_id, DATE(MAX(used_on)) AS last_use
        FROM (SELECT artifact_id, use_date AS used_on FROM " . self::OWNERS_USES . "
            UNION ALL SELECT Title, PlayDate FROM responses) item_dates
        GROUP BY artifact_id)";

    /**
     * Uses recorded by their Item's owner, the only Uses last use and use
     * counts read. The Item is joined as owner_items.
     */
    public const OWNERS_USES = 'uses JOIN games owner_items
        ON owner_items.id = uses.artifact_id AND owner_items.user_id = uses.user_id';

    /** What create gives a missing or blank field, besides today's date and the owner's interval. */
    public const DEFAULTS = ['MnT' => 30, 'MxT' => 60, 'MnP' => 1, 'MxP' => 1, 'SS' => '01', 'Age' => 0, 'is_kept' => 1];

    private const WRITABLE = [
        'Title', 'FullTitle', 'Notes', 'image_url',
        'bgg_url', 'bgg_player_votes', 'bgg_age_basis', 'BGG_Rat',
        'Acq', 'type_id',
        'is_kept', 'is_in_secondary_collection', 'is_digital', 'is_physical', 'to_get_rid_of',
        'Candidate', 'SS', 'MnT', 'MxT', 'MnP', 'MxP', 'Age', 'age_max', 'Wt', 'Yr', 'Av', 'FavCt',
        'Access', 'OrigPlat', 'System', 'interaction_frequency_days',
    ];

    /** The BoardGameGeek columns, stored together by item_bgg_fields_for_storage(). */
    private const BGG_COLUMNS = ['bgg_url', 'bgg_player_votes', 'bgg_age_basis', 'BGG_Rat'];

    /** Fields an update gives their create default when blank. */
    private const BLANK_TAKES_DEFAULT = ['MnT', 'MxT', 'MnP', 'MxP', 'SS', 'Age', 'Acq'];

    /** The list's flag filters and the column each one reads. */
    private const FLAG_FILTERS = [
        'kept' => 'is_kept',
        'secondary_collection' => 'is_in_secondary_collection',
        'physical' => 'is_physical',
        'digital' => 'is_digital',
        'to_get_rid_of' => 'to_get_rid_of',
    ];

    /**
     * Everything that points at an Item, the one list delete and merge both
     * read: a new table with an Item column belongs here. Rows are matched
     * by Item id alone once the owner holds the Item's lock, which covers
     * legacy rows written before user_id was recorded.
     *
     * Delete deletes each table's rows for the Item, first the rows that
     * belong to them ('belonging_rows': a table and its column holding
     * their id). Merge moves them to the survivor; where 'collides', the
     * survivor's own row wins and the loser's is deleted. A column with an
     * 'own_item_column' is a second link, beside the row's own Item in that
     * column: delete clears it, and merge clears it where it now points at
     * the row's own Item.
     */
    private const POINTING_AT_ITEM = [
        ['table' => 'uses', 'column' => 'artifact_id', 'belonging_rows' => ['uses_players', 'use_id']],
        ['table' => 'responses', 'column' => 'Title'],
        ['table' => 'sweetspots', 'column' => 'Title'],
        // Participants follow a proposal by cascade.
        ['table' => 'proposal_outcomes', 'column' => 'item_id'],
        ['table' => 'proposal_outcomes', 'column' => 'chosen_item_id', 'own_item_column' => 'item_id'],
        ['table' => 'item_tags', 'column' => 'artifact_id', 'collides' => true],
        ['table' => 'item_bgg_ratings', 'column' => 'artifact_id', 'collides' => true],
        ['table' => 'event_items', 'column' => 'artifact_id', 'collides' => true],
    ];

    private Types $types;

    public function __construct(private mysqli $db, private int $userId)
    {
        $this->types = new Types($db, $userId);
    }

    /** Create the Item with its tags and return its id. */
    public function create(array $input): int
    {
        return $this->transaction(function () use ($input) {
            $item = $this->fillBlanks($this->writable($input), $this->createDefaults() + [
                'interaction_frequency_days' => (new Preferences($this->db, $this->userId))->get()['default_use_interval'],
                'type_id' => null,
            ]);
            $this->validate($item, $item);
            $columns = $this->columns($item, $item) + [
                'user_id' => $this->userId,
                'CandidateGroupDate' => date('Y-m-d'),
                'UsedRecUserCt' => 0,
            ];
            $this->statement(
                'INSERT INTO games (`' . implode('`, `', array_keys($columns)) . '`)
                 VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                str_repeat('s', count($columns)), array_values($columns)
            )->close();
            $id = (int) $this->db->insert_id;
            if (array_key_exists('tags', $input)) {
                $this->replaceTags($id, $input['tags']);
            }
            return $id;
        });
    }

    /** The owner's Item row with its type name and tags (a sorted list), or null. */
    public function find(int $id): ?array
    {
        $row = $this->rows(
            'SELECT types.objectType AS type_name, games.*
             FROM games LEFT JOIN types ON types.id = games.type_id
             WHERE games.id = ? AND games.user_id = ?',
            'ii', [$id, $this->userId]
        )[0] ?? null;
        return $row === null ? null : with_item_tags($this->db, [$row], $this->userId)[0];
    }

    /**
     * The owner's Items ordered by Title, then id. Each row is the Item's
     * own columns plus type_name (or null), tags (a list), last_use (Y-m-d
     * or null) and use_count (its recorded Uses, an int).
     *
     * Filters combine with AND; null or missing means no filter.
     * kept, secondary_collection, physical, digital and to_get_rid_of take
     * true (the flag is set) or false (it isn't, including a null column).
     * type_ids takes a list of type ids; [] lists nothing. tag is normalized
     * like item tags and title matches a substring; blank means no filter.
     * An unknown key throws InvalidArgumentException.
     */
    public function list(array $filters = []): array
    {
        $unknown = array_diff(array_keys($filters), [...array_keys(self::FLAG_FILTERS), 'type_ids', 'tag', 'title']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Item list filter: ' . implode(', ', $unknown) . '.');
        }
        $typeIds = $filters['type_ids'] ?? null;
        if ($typeIds === []) {
            return [];
        }

        $where = 'games.user_id = ?';
        $types = 'i';
        $params = [$this->userId];
        foreach (self::FLAG_FILTERS as $filter => $column) {
            if (isset($filters[$filter])) {
                $where .= ' AND ' . artifact_flag_sql("games.{$column}", $filters[$filter]);
            }
        }
        if ($typeIds !== null) {
            $where .= ' AND games.type_id IN (' . implode(', ', array_fill(0, count($typeIds), '?')) . ')';
            $types .= str_repeat('s', count($typeIds));
            array_push($params, ...array_map('strval', array_values($typeIds)));
        }
        [$tagSql, $tagTypes, $tagParams] = $this->tagFilter($filters['tag'] ?? '');
        $where .= $tagSql;
        $types .= $tagTypes;
        array_push($params, ...$tagParams);
        $title = trim((string) ($filters['title'] ?? ''));
        if ($title !== '') {
            $where .= ' AND games.Title LIKE ?';
            $types .= 's';
            $params[] = '%' . $title . '%';
        }

        $rows = $this->rows(
            'SELECT games.*, types.objectType AS type_name, item_last_use.last_use,
                COALESCE(item_uses.use_count, 0) AS use_count
             FROM games
                LEFT JOIN types ON types.id = games.type_id
                LEFT JOIN ' . self::LAST_USE . ' item_last_use ON item_last_use.artifact_id = games.id
                LEFT JOIN (SELECT artifact_id, COUNT(*) AS use_count FROM ' . self::OWNERS_USES . ' GROUP BY artifact_id) item_uses
                    ON item_uses.artifact_id = games.id
             WHERE ' . $where . '
             ORDER BY games.Title, games.id',
            $types, $params
        );
        return array_map(
            fn (array $row) => array_replace($row, ['use_count' => (int) $row['use_count']]),
            with_item_tags($this->db, $rows, $this->userId)
        );
    }

    /**
     * Patch the Item with the given fields. A blank time, player count,
     * sweet spot, age or acquisition date takes its create default; a blank
     * type keeps the current type; missing tags leave the tags unchanged.
     */
    public function update(int $id, array $changes): void
    {
        $this->transaction(function () use ($id, $changes) {
            $current = $this->lockedItem($id);
            $patch = $this->writable($changes);
            $patch = $this->fillBlanks($patch, array_intersect_key(
                $this->createDefaults(), array_flip(self::BLANK_TAKES_DEFAULT), $patch
            ));
            if (array_key_exists('type_id', $patch) && $this->isBlank($patch['type_id'])) {
                unset($patch['type_id']);
            }
            $this->validate(array_replace($current, $patch), $patch);
            $columns = $this->columns(array_replace($current, $patch), $patch);
            if ($columns !== []) {
                $this->statement(
                    'UPDATE games SET `' . implode('` = ?, `', array_keys($columns)) . '` = ?
                     WHERE id = ? AND user_id = ?',
                    str_repeat('s', count($columns)) . 'ii', [...array_values($columns), $id, $this->userId]
                )->close();
            }
            if (array_key_exists('tags', $changes)) {
                $this->replaceTags($id, $changes['tags']);
            }
        });
    }

    /**
     * Delete the Item with everything that points at it: its Uses with
     * their people, item proposals with their participants, BGG ratings,
     * legacy plays, sweet spots, tags and Event plan entries. A proposal
     * that chose it instead keeps the name and loses only the link.
     */
    public function delete(int $id): void
    {
        $this->transaction(function () use ($id) {
            $this->lockedItem($id);
            foreach (self::POINTING_AT_ITEM as $reference) {
                ['table' => $table, 'column' => $column] = $reference;
                if (isset($reference['own_item_column'])) {
                    $this->statement("UPDATE {$table} SET {$column} = NULL WHERE {$column} = ?", 'i', [$id])->close();
                    continue;
                }
                if (isset($reference['belonging_rows'])) {
                    [$rowsTable, $rowsColumn] = $reference['belonging_rows'];
                    $this->statement(
                        "DELETE {$rowsTable} FROM {$rowsTable} JOIN {$table} ON {$table}.id = {$rowsTable}.{$rowsColumn}
                         WHERE {$table}.{$column} = ?",
                        'i', [$id]
                    )->close();
                }
                $this->statement("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$id])->close();
            }
            $this->statement('DELETE FROM games WHERE id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        });
    }

    /**
     * Merge the loser into the survivor, for duplicate records: everything
     * that points at the loser moves to the survivor, which keeps its own
     * fields, and the loser is deleted. Where the survivor already has a
     * tag, BGG rating or Event plan entry, the survivor's wins. A proposal
     * where the loser was chosen instead of the survivor loses that link.
     *
     * Both Items must be the owner's (OutOfBoundsException) and differ
     * (InvalidArgumentException).
     */
    public function merge(int $survivorId, int $loserId): void
    {
        if ($survivorId === $loserId) {
            throw new InvalidArgumentException('Cannot merge an item into itself.');
        }
        $this->transaction(function () use ($survivorId, $loserId) {
            // Lower id first, so two crossed merges can't deadlock.
            $this->lockedItem(min($survivorId, $loserId));
            $this->lockedItem(max($survivorId, $loserId));
            foreach (self::POINTING_AT_ITEM as $reference) {
                ['table' => $table, 'column' => $column] = $reference;
                $collides = $reference['collides'] ?? false;
                $this->statement(
                    'UPDATE ' . ($collides ? 'IGNORE ' : '') . "{$table} SET {$column} = ? WHERE {$column} = ?",
                    'ii', [$survivorId, $loserId]
                )->close();
                if ($collides) {
                    $this->statement("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$loserId])->close();
                }
                if (isset($reference['own_item_column'])) {
                    $this->statement(
                        "UPDATE {$table} SET {$column} = NULL WHERE {$column} = ? AND {$column} = {$reference['own_item_column']}",
                        'i', [$survivorId]
                    )->close();
                }
            }
            $this->statement('DELETE FROM games WHERE id = ? AND user_id = ?', 'ii', [$loserId, $this->userId])->close();
        });
    }

    /** Mark the Item kept or not. Unlike update, the rest of the Item isn't checked against the item rules. */
    public function setKept(int $id, bool $kept): void
    {
        $this->setColumn($id, 'is_kept', (int) $kept);
    }

    /** Mark the Item to get rid of or not. Unlike update, the rest of the Item isn't checked against the item rules. */
    public function setToGetRidOf(int $id, bool $toGetRidOf): void
    {
        $this->setColumn($id, 'to_get_rid_of', (int) $toGetRidOf);
    }

    /**
     * Hide the Item from the dashboard's priority queue until today plus
     * $days (below 1 counts as 1) and return that date as Y-m-d.
     */
    public function snooze(int $id, int $days): string
    {
        $until = (new DateTime('today'))->modify('+' . max(1, $days) . ' days')->format('Y-m-d');
        $this->setColumn($id, 'snoozed_until', $until);
        return $until;
    }

    /** $column must be a trusted column name, never input. */
    private function setColumn(int $id, string $column, int|string $value): void
    {
        $this->transaction(function () use ($id, $column, $value) {
            $this->lockedItem($id);
            $this->statement(
                "UPDATE games SET `{$column}` = ? WHERE id = ? AND user_id = ?",
                'sii', [$value, $id, $this->userId]
            )->close();
        });
    }

    /** Replace the Item's tags with the parsed $tags; the caller has written the owner's Item in this transaction. */
    private function replaceTags(int $id, $tags): void
    {
        $this->statement('DELETE FROM item_tags WHERE artifact_id = ? AND user_id = ?', 'ii', [$id, $this->userId])->close();
        foreach (parse_item_tags_input($tags) as $tag) {
            $this->statement(
                'INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (?, ?, ?)',
                'iis', [$this->userId, $id, $tag]
            )->close();
        }
    }

    /** The list's tag filter as [sql, types, params]: the owner's Items with the normalized tag, or no filter when it's blank. */
    private function tagFilter($tag): array
    {
        $tag = normalize_item_tag($tag);
        if ($tag === null) {
            return ['', '', []];
        }
        return [' AND games.id IN (SELECT artifact_id FROM item_tags WHERE user_id = ? AND tag = ?)', 'is', [$this->userId, $tag]];
    }

    private function lockedItem(int $id): array
    {
        return $this->rows('SELECT * FROM games WHERE id = ? AND user_id = ? FOR UPDATE', 'ii', [$id, $this->userId])[0]
            ?? throw new OutOfBoundsException('Item not found.');
    }

    /** Create's default for each blank field it fills, besides the owner's interval and the type. */
    private function createDefaults(): array
    {
        return self::DEFAULTS + ['Acq' => date('Y-m-d')];
    }

    private function fillBlanks(array $fields, array $defaults): array
    {
        foreach ($defaults as $field => $default) {
            if ($this->isBlank($fields[$field] ?? null)) {
                $fields[$field] = $default;
            }
        }
        return $fields;
    }

    /** The input's writable fields; id, user_id and snoozed_until are never among them. */
    private function writable(array $input): array
    {
        $fields = array_intersect_key($input, array_flip(self::WRITABLE));
        $listFields = array_keys(array_filter($fields, fn ($value) => $value !== null && !is_scalar($value)));
        if ($listFields !== []) {
            throw new ItemInvalid(array_map(fn ($field) => "{$field} must be a single value.", $listFields));
        }
        return $fields;
    }

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * Throw ItemInvalid listing every rule $item breaks. The type is checked
     * only when $changes sets one: it must be one of the owner's types.
     */
    private function validate(array $item, array $changes): void
    {
        $errors = [];

        $title = (string) ($item['Title'] ?? '');
        if (trim($title) === '') {
            $errors[] = 'Title cannot be blank.';
        } elseif (strlen($title) < 2 || strlen($title) > 255) {
            $errors[] = 'Title must be between 2 and 255 characters.';
        }

        if (!in_array((string) ($item['is_kept'] ?? ''), ['0', '1'], true)) {
            $errors[] = 'Kept must be true or false.';
        }

        $numbers = ['MnT' => 'Minimum Time', 'MxT' => 'Maximum Time', 'MnP' => 'Minimum User Count', 'MxP' => 'Maximum User Count'];
        foreach ($numbers as $field => $label) {
            if (!$this->isBlank($item[$field] ?? null) && !is_numeric($item[$field])) {
                $errors[] = "{$label} must be a number.";
            }
        }
        foreach ([['MnT', 'MxT', 'Minimum Time cannot exceed Maximum Time.'], ['MnP', 'MxP', 'Minimum User Count cannot exceed Maximum User Count.']] as [$min, $max, $message]) {
            if (is_numeric($item[$min] ?? null) && is_numeric($item[$max] ?? null) && (int) $item[$min] > (int) $item[$max]) {
                $errors[] = $message;
            }
        }

        if (!$this->isBlank($item['Age'] ?? null) && (!is_numeric($item['Age']) || (int) $item['Age'] < 0)) {
            $errors[] = 'Minimum Age must be a non-negative number.';
        }

        $year = normalize_item_year($item['Yr'] ?? null);
        if ($year !== null && !preg_match('/^\d{1,4}$/', $year)) {
            $errors[] = 'Year must be a 1 to 4 digit number.';
        }

        $acquired = (string) ($item['Acq'] ?? '');
        if ($acquired !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $acquired);
            if (!$date || $date->format('Y-m-d') !== $acquired) {
                $errors[] = 'Tracking Start Date must be a valid date (YYYY-MM-DD).';
            }
        }

        $bggUrl = trim((string) ($item['bgg_url'] ?? ''));
        if ($bggUrl !== '' && normalize_item_bgg_url($bggUrl) === '') {
            $errors[] = 'BoardGameGeek Link must be a boardgamegeek.com, rpggeek.com, or videogamegeek.com page.';
        }

        $frequency = $item['interaction_frequency_days'] ?? null;
        if (!$this->isBlank($frequency) && (!is_numeric($frequency) || (float) $frequency <= 0)) {
            $errors[] = 'Interaction Frequency must be a positive number.';
        }

        if (!$this->isBlank($changes['type_id'] ?? null) && $this->ownerType($changes['type_id']) === null) {
            $errors[] = 'Type must be one of your types.';
        }

        if ($errors !== []) {
            throw new ItemInvalid($errors);
        }
    }

    private function ownerType($typeId): ?array
    {
        $typeId = trim((string) $typeId);
        return preg_match('/^[1-9][0-9]*$/', $typeId) ? $this->types->find((int) $typeId) : null;
    }

    /**
     * The column values to write for the $changes, normalized. The
     * BoardGameGeek columns are written together, from the $item they
     * leave, and the type name follows type_id.
     */
    private function columns(array $item, array $changes): array
    {
        $columns = [];
        foreach ($changes as $field => $value) {
            $columns[$field] = match ($field) {
                'is_kept', 'to_get_rid_of' => normalize_kept_value((string) $value),
                'is_in_secondary_collection' => normalize_secondary_membership((string) $value),
                'is_digital', 'is_physical' => normalize_format_flag($value === null ? null : (string) $value),
                'Yr' => normalize_item_year($value),
                'image_url' => normalize_item_image_url($value),
                'type_id', 'age_max', 'FavCt', 'interaction_frequency_days' => $this->isBlank($value) ? null : trim((string) $value),
                default => $value,
            };
        }
        if (array_key_exists('type_id', $columns)) {
            $columns['type'] = $columns['type_id'] === null ? null : $this->ownerType($columns['type_id'])['name'];
        }
        if (array_intersect_key($changes, array_flip(self::BGG_COLUMNS)) !== []) {
            $columns = item_bgg_fields_for_storage($item) + $columns;
        }
        return $columns;
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
