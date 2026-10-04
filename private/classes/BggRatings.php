<?php

require_once dirname(__DIR__) . '/bgg_ratings.php';
require_once __DIR__ . '/Items.php';

/** BoardGameGeek could not be reached or answered with nothing usable. */
final class BggUnreachable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Could not reach BoardGameGeek.');
    }
}

/**
 * The owner's BGG ratings: each BGG reviewer's rating and comment on their
 * items, imported from BoardGameGeek or entered by hand on Edit Item, plus
 * the BGG average on their items and the reviewer they name on Settings.
 * Every read and write is scoped to the owner.
 *
 * The import never replaces a hand entry; request() replaces one only when
 * BoardGameGeek has an entry for the item.
 *
 * Refusals throw: an item the owner doesn't have throws
 * OutOfBoundsException, bad input or an unknown BGG user throws
 * InvalidArgumentException, and BoardGameGeek being unreachable throws
 * BggUnreachable. Each message is ready for the page.
 */
final class BggRatings
{
    /** @var callable|null fetches a BGG API URL; null for the real HTTP fetch */
    private $getJson;
    private Items $items;

    public function __construct(private mysqli $db, private int $userId, ?callable $getJson = null)
    {
        $this->getJson = $getJson;
        $this->items = new Items($db, $userId);
    }

    /**
     * The BGG reviewers whose ratings the owner has, plus their own
     * reviewer from Settings before any import, alphabetically.
     */
    public function reviewers(): array
    {
        // Through games, so a deleted item's rating does not keep an empty column.
        $reviewers = array_column($this->rows(
            'SELECT DISTINCT r.bgg_username
             FROM item_bgg_ratings r
             JOIN games g ON g.id = r.artifact_id AND g.user_id = r.user_id
             WHERE r.user_id = ?
             ORDER BY r.bgg_username',
            'i', [$this->userId]
        ), 'bgg_username');
        $own = $this->ownReviewer();
        if ($own !== null && !in_array(strtolower($own), array_map('strtolower', $reviewers), true)) {
            $reviewers[] = $own;
            usort($reviewers, 'strcasecmp');
        }
        return $reviewers;
    }

    /** The BGG user the owner named as their reviewer on Settings, or null. */
    public function ownReviewer(): ?string
    {
        $row = $this->rows('SELECT bgg_username FROM users WHERE id = ?', 'i', [$this->userId])[0] ?? [];
        $username = trim((string) ($row['bgg_username'] ?? ''));
        return $username === '' ? null : $username;
    }

    /**
     * Settings' BoardGameGeek reviewer: blank clears it, anything else must
     * be a BGG user and is stored as BGG spells it. Ratings already imported
     * stay either way. Returns what changed, or null when nothing did.
     */
    public function setOwnReviewer(string $raw): ?string
    {
        $raw = trim($raw);
        if (mb_strlen($raw) > 64) {
            throw new InvalidArgumentException('A BoardGameGeek username is at most 64 characters.');
        }
        $current = $this->ownReviewer();
        if (strcasecmp($raw, (string) $current) === 0) {
            return null;
        }
        if ($raw === '') {
            $username = null;
            $message = 'You no longer have a BoardGameGeek reviewer.';
        } else {
            $username = $this->findBggUser($raw)['username'];
            $message = 'Your BoardGameGeek reviewer is now ' . $username . '.';
        }
        $this->statement('UPDATE users SET bgg_username = ? WHERE id = ?', 'si', [$username, $this->userId])->close();
        return $message;
    }

    /**
     * [item id => [reviewer => ['rating', 'comment', 'url', 'manual']]] for
     * those of $itemIds the owner has. url is the item's BGG link.
     */
    public function forItems(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_unique(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }
        $rows = $this->rows(
            'SELECT r.artifact_id, r.bgg_username, r.rating, r.comment, r.is_manual, g.bgg_url
             FROM item_bgg_ratings r
             JOIN games g ON g.id = r.artifact_id AND g.user_id = r.user_id
             WHERE r.artifact_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ') AND r.user_id = ?
             ORDER BY r.artifact_id, r.bgg_username',
            str_repeat('i', count($itemIds) + 1), [...$itemIds, $this->userId]
        );
        $ratings = [];
        foreach ($rows as $row) {
            $ratings[(int) $row['artifact_id']][$row['bgg_username']] = [
                'rating' => $row['rating'] === null ? null : (float) $row['rating'],
                'comment' => $row['comment'],
                'url' => (string) $row['bgg_url'],
                'manual' => (bool) $row['is_manual'],
            ];
        }
        return $ratings;
    }

    /**
     * [thing id => [reviewer => ['rating', 'comment', 'artifact_id']]] for the
     * owner's items that link to a BGG thing, kept or not, so Search BGG can
     * show what their reviewers said. When two items share a thing, the
     * lower item id speaks for it.
     */
    public function byThing(): array
    {
        $linked = $this->thingsByItem();
        $byThing = [];
        foreach ($this->forItems(array_keys($linked)) as $itemId => $ratings) {
            foreach ($ratings as $reviewer => $rating) {
                $byThing[$linked[$itemId]][$reviewer] ??= [
                    'rating' => $rating['rating'],
                    'comment' => $rating['comment'],
                    'artifact_id' => $itemId,
                ];
            }
        }
        return $byThing;
    }

    /**
     * [item id => BGG thing id] for the owner's items whose link names a
     * thing, by item id. With $keptOnly, only the items they keep.
     */
    public function thingsByItem(bool $keptOnly = false): array
    {
        $rows = $this->rows(
            "SELECT id, bgg_url FROM games WHERE user_id = ? AND bgg_url IS NOT NULL AND bgg_url <> ''"
                . ($keptOnly ? ' AND is_kept = 1' : '') . ' ORDER BY id',
            'i', [$this->userId]
        );
        $linked = [];
        foreach ($rows as $row) {
            $thingId = bgg_thing_id_from_url($row['bgg_url']);
            if ($thingId > 0) {
                $linked[(int) $row['id']] = $thingId;
            }
        }
        return $linked;
    }

    /**
     * Edit Item's rating editor: the owner's own rating and comment for one
     * of their reviewers on one item, with or without a BGG link. A blank
     * rating or comment stores none; both blank removes the entry. Returns
     * the message for the page.
     */
    public function save(int $itemId, string $reviewer, string $rating, string $comment): string
    {
        $this->ownedItem($itemId);
        $known = null;
        foreach ($this->reviewers() as $candidate) {
            if (strcasecmp($candidate, trim($reviewer)) === 0) {
                $known = $candidate;
            }
        }
        if ($known === null) {
            throw new InvalidArgumentException(trim($reviewer) . ' is not one of your BoardGameGeek reviewers.');
        }
        $score = bgg_rating_from_input($rating);
        if ($score === false) {
            throw new InvalidArgumentException('A rating is a number from 1 to 10.');
        }
        $comment = trim($comment);
        $comment = $comment === '' ? null : $comment;

        $whose = $known . (substr($known, -1) === 's' ? "'" : "'s");
        if ($score === null && $comment === null) {
            $this->delete($itemId, $known, false);
            return 'Removed ' . $whose . ' rating and comment.';
        }
        // An edited score is the owner's, not BGG's, so it carries no BGG rating date.
        $this->store($itemId, $known, ['rating' => $score, 'comment' => $comment, 'rated_at' => null], true);
        return 'Saved ' . $whose . ' rating and comment.';
    }

    /**
     * Edit Item's "Request <user> data": one item's entry, fetched now,
     * replacing even a hand entry when BGG has one. The item must link to a
     * BGG thing. Returns what BGG had, ready for the page.
     */
    public function request(int $itemId, string $username): string
    {
        $thingId = bgg_thing_id_from_url($this->ownedItem($itemId)['bgg_url'] ?? '');
        if ($thingId <= 0) {
            throw new InvalidArgumentException('Add a BoardGameGeek link to this item first.');
        }
        $bggUser = $this->findBggUser($username);
        $entry = $this->refresh($itemId, $thingId, $bggUser);
        if ($entry === false) {
            throw new BggUnreachable();
        }
        if ($entry === null) {
            $kept = $this->forItems([$itemId])[$itemId][$bggUser['username']] ?? null;
            return $bggUser['username'] . ' has not rated or commented on this item on BoardGameGeek'
                . ($kept === null ? '.' : ', so your entry stays.');
        }
        if ($entry['rating'] === null) {
            return $bggUser['username'] . ' commented on this item without rating it.';
        }
        return $bggUser['username'] . ' rated it ' . bgg_score_text($entry['rating']) . ' out of 10.';
    }

    /**
     * Looks up $username's entry for every owner item with a BGG link and
     * stores what they rated or commented on, leaving hand entries alone. An
     * item BGG answered with no entry loses its old row; an item whose
     * lookup failed keeps it. $pauseMs spaces the requests out so a full
     * collection does not hammer BGG. $onProgress, when given, hears
     * ($counts, $total) before the first lookup and after each one.
     *
     * Returns ['username' as BGG spells it, 'checked', 'imported', 'removed', 'failed'].
     */
    public function import(string $username, int $pauseMs = 250, ?callable $onProgress = null): array
    {
        $bggUser = $this->findBggUser($username);
        $linked = $this->thingsByItem();
        $hadRating = $this->forItems(array_keys($linked));
        $toCheck = array_filter($linked, fn ($itemId) =>
            empty($hadRating[$itemId][$bggUser['username']]['manual']), ARRAY_FILTER_USE_KEY);

        $counts = ['username' => $bggUser['username'], 'checked' => 0, 'imported' => 0, 'removed' => 0, 'failed' => 0];
        if ($onProgress !== null) {
            $onProgress($counts, count($toCheck));
        }
        foreach ($toCheck as $itemId => $thingId) {
            if ($counts['checked'] > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $counts['checked']++;
            $entry = $this->refresh($itemId, $thingId, $bggUser);
            if ($entry === false) {
                $counts['failed']++;
            } elseif ($entry !== null) {
                $counts['imported']++;
            } elseif (isset($hadRating[$itemId][$bggUser['username']])) {
                $counts['removed']++;
            }
            if ($onProgress !== null) {
                $onProgress($counts, count($toCheck));
            }
        }

        // An item whose link was removed or no longer names a thing keeps no
        // imported rating. A hand entry needs no link, so it stays.
        $sql = 'DELETE FROM item_bgg_ratings WHERE user_id = ? AND bgg_username = ? AND is_manual = 0';
        if ($linked !== []) {
            $sql .= ' AND artifact_id NOT IN (' . implode(',', array_fill(0, count($linked), '?')) . ')';
        }
        $unlinked = $this->statement($sql, 'is' . str_repeat('i', count($linked)), [$this->userId, $bggUser['username'], ...array_keys($linked)]);
        $counts['removed'] += $unlinked->affected_rows;
        $unlinked->close();
        return $counts;
    }

    /**
     * Copies BGG's average rating onto each of the owner's items that link
     * to a thing. Items that share a thing are fetched once. A failed or
     * queued reply leaves the stored average alone; a real answer with no
     * average clears it. Returns ['checked', 'imported', 'cleared', 'failed'].
     */
    public function importAverages(int $pauseMs = 250): array
    {
        $byThing = [];
        foreach ($this->thingsByItem() as $itemId => $thingId) {
            $byThing[$thingId][] = $itemId;
        }

        $counts = ['checked' => 0, 'imported' => 0, 'cleared' => 0, 'failed' => 0];
        foreach ($byThing as $thingId => $itemIds) {
            if ($counts['checked'] > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $counts['checked'] += count($itemIds);
            try {
                $average = bgg_overall_rating_from_dynamic_json($this->fetch(bgg_dynamic_info_url($thingId)));
            } catch (BggUnreachable $e) {
                $average = false;
            }
            if ($average === false) {
                $counts['failed'] += count($itemIds);
                continue;
            }
            foreach ($itemIds as $itemId) {
                $this->storeAverage($itemId, $average);
                $counts[$average === null ? 'cleared' : 'imported']++;
            }
        }
        return $counts;
    }

    /** The owner's item, or OutOfBoundsException. */
    private function ownedItem(int $itemId): array
    {
        return $this->items->find($itemId) ?? throw new OutOfBoundsException('Item not found.');
    }

    /** ['id', 'username' as BGG spells it] for a BGG username. */
    private function findBggUser(string $username): array
    {
        $username = trim($username);
        $json = $this->fetch(bgg_api_root() . '/users?username=' . rawurlencode($username));
        return bgg_user_from_users_json($json, $username)
            ?? throw new InvalidArgumentException('No BoardGameGeek user named ' . $username . '.');
    }

    /**
     * Fetches one BGG user's entry for one item and stores it, replacing even
     * a hand entry. Returns the entry (null when they have none, which clears
     * an imported row but keeps a hand entry), or false when BGG could not be
     * reached, which leaves the old row alone.
     */
    private function refresh(int $itemId, int $thingId, array $bggUser): array|null|false
    {
        $url = bgg_api_root() . '/collections?objectid=' . $thingId . '&objecttype=thing&userid=' . $bggUser['id'];
        try {
            $json = $this->fetch($url);
        } catch (BggUnreachable $e) {
            return false;
        }
        // Only a real {"items": [...]} answer may clear a rating. An empty or
        // garbled body, as BGG sends while it queues a request, counts as a failure.
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            return false;
        }
        $entry = bgg_rating_from_collection_json($json);
        if ($entry === null) {
            $this->delete($itemId, $bggUser['username'], true);
            return null;
        }
        $this->store($itemId, $bggUser['username'], $entry, false);
        return $entry;
    }

    /** BoardGameGeek's answer to one API URL; any failure to get one throws BggUnreachable. */
    private function fetch(string $url): string
    {
        try {
            return (string) bgg_fetch($url, $this->getJson);
        } catch (Throwable $e) {
            throw new BggUnreachable();
        }
    }

    /** Stores one reviewer's ['rating', 'comment', 'rated_at'] for one item, replacing what it had. */
    private function store(int $itemId, string $reviewer, array $entry, bool $manual): void
    {
        $this->statement(
            'INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating, comment, rated_at, is_manual)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), rated_at = VALUES(rated_at), is_manual = VALUES(is_manual)',
            'iisdssi',
            [$this->userId, $itemId, $reviewer, $entry['rating'], $entry['comment'], $entry['rated_at'], (int) $manual]
        )->close();
    }

    /** Removes one reviewer's entry for one item; with $keepManual, a hand entry stays. */
    private function delete(int $itemId, string $reviewer, bool $keepManual): void
    {
        $this->statement(
            'DELETE FROM item_bgg_ratings WHERE user_id = ? AND artifact_id = ? AND bgg_username = ?'
                . ($keepManual ? ' AND is_manual = 0' : ''),
            'iis', [$this->userId, $itemId, $reviewer]
        )->close();
    }

    private function storeAverage(int $itemId, ?string $average): void
    {
        $this->statement('UPDATE games SET BGG_Rat = ? WHERE id = ? AND user_id = ?', 'sii', [$average, $itemId, $this->userId])->close();
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
