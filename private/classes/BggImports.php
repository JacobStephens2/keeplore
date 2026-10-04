<?php

require_once __DIR__ . '/BggRatings.php';

/**
 * The owner's BGG imports: full imports of their BoardGameGeek reviewer, run
 * in the background because a large collection outlasts a web request.
 * Settings queues one, private/crons/run_bgg_import_jobs.php runs it each
 * minute through runQueued(), and Settings reads its progress back while it
 * runs. bgg_import_jobs holds one row per import. Every read and write of it
 * goes through here, scoped to the owner except for the cron worker.
 */
final class BggImports
{
    // A job the worker has not touched for this long is dead: a running one
    // stopped mid-import, and a queued one was never picked up.
    private const STALE_MINUTES = 10;

    private const LOCK = 'keeplore_bgg_import_jobs';

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /**
     * What Settings shows: whether an import is under way, whether the owner
     * can start one (they need a reviewer and no import under way), and the
     * status line.
     */
    public function status(): array
    {
        $job = $this->latest();
        $active = self::isActive($job);
        return [
            'active' => $active,
            'can_queue' => !$active && (new BggRatings($this->db, $this->userId))->ownReviewer() !== null,
            'text' => self::statusText($job),
        ];
    }

    /**
     * Queues a full import of the reviewer named on Settings. ['ok' => true,
     * 'message'] or ['ok' => false, 'error'] when there is no reviewer or an
     * import is already queued or running.
     */
    public function queue(): array
    {
        $username = (new BggRatings($this->db, $this->userId))->ownReviewer();
        if ($username === null) {
            return ['ok' => false, 'error' => 'Name a BoardGameGeek reviewer above first.'];
        }
        $latest = $this->latest();
        if (self::isActive($latest)) {
            return ['ok' => false, 'error' => 'An import of ' . $latest['bgg_username'] . ' is already queued or running.'];
        }
        self::statement($this->db, 'INSERT INTO bgg_import_jobs (user_id, bgg_username) VALUES (?, ?)', 'is', [$this->userId, $username])->close();
        return ['ok' => true, 'message' => self::statusText($this->latest())];
    }

    /**
     * The cron worker: runs every owner's queued imports, oldest first, one
     * at a time so BGG sees one import's requests at once. A second worker
     * started while one runs does nothing. $getJson fetches a BGG API URL,
     * null for the real HTTP fetch. Returns how many imports it ran.
     */
    public static function runQueued(mysqli $db, ?callable $getJson = null, int $pauseMs = 250): int
    {
        $lock = $db->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetch_row();
        if ((int) ($lock[0] ?? 0) !== 1) {
            return 0;
        }
        $ran = 0;
        try {
            self::failStale($db);
            while (($job = self::claimNext($db)) !== null) {
                self::run($db, $job, $getJson, $pauseMs);
                $ran++;
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
        return $ran;
    }

    // The owner's most recent import, or null. Counts are ints; total is
    // null until the worker knows how many items it will check.
    private function latest(): ?array
    {
        self::failStale($this->db);
        $stmt = self::statement(
            $this->db,
            'SELECT id, bgg_username, status, total, checked, imported, removed, failed, error, created_at, started_at, finished_at
             FROM bgg_import_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            'i', [$this->userId]
        );
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$job) {
            return null;
        }
        foreach (['id', 'checked', 'imported', 'removed', 'failed'] as $count) {
            $job[$count] = (int) $job[$count];
        }
        $job['total'] = $job['total'] === null ? null : (int) $job['total'];
        return $job;
    }

    private static function isActive(?array $job): bool
    {
        return $job !== null && in_array($job['status'], ['queued', 'running'], true);
    }

    // Settings' one-line account of an import.
    private static function statusText(?array $job): string
    {
        if ($job === null) {
            return '';
        }
        $who = $job['bgg_username'];
        switch ($job['status']) {
            case 'queued':
                return 'Import of ' . $who . ' queued. It starts within a minute.';
            case 'running':
                if ($job['total'] === null) {
                    return 'Importing ' . $who . ': starting.';
                }
                return 'Importing ' . $who . ': checked ' . $job['checked'] . ' of ' . $job['total'] . ($job['total'] === 1 ? ' item, ' : ' items, ')
                    . $job['imported'] . ' rated or commented so far.';
            case 'done':
                return 'Imported ' . $who . ' on ' . substr((string) $job['finished_at'], 0, 10) . ': checked '
                    . $job['checked'] . ($job['checked'] === 1 ? ' item, ' : ' items, ') . $job['imported'] . ' rated or commented, '
                    . $job['removed'] . ' removed, ' . $job['failed'] . ' failed.';
            default:
                return 'Import of ' . $who . ' failed: ' . $job['error'];
        }
    }

    // Marks dead jobs failed, so they neither block a new import nor show as
    // active forever. A worker that wakes after this cannot revive its job:
    // record() only writes to a running job.
    private static function failStale(mysqli $db): void
    {
        $stale = 'updated_at < NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE';
        $db->query(
            "UPDATE bgg_import_jobs
             SET status = 'failed', error = 'The import stopped before it finished.', finished_at = NOW()
             WHERE status = 'running' AND {$stale}"
        );
        // A queued job may wait behind another owner's import; it is only dead
        // when the worker shows no sign of life: nothing running, nothing just done.
        $alive = $db->query(
            "SELECT COUNT(*) FROM bgg_import_jobs
             WHERE status = 'running' OR (status = 'done' AND finished_at > NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE)'
        )->fetch_row();
        if ((int) $alive[0] === 0) {
            $db->query(
                "UPDATE bgg_import_jobs
                 SET status = 'failed', error = 'It never started. The background worker may not be running.', finished_at = NOW()
                 WHERE status = 'queued' AND {$stale}"
            );
        }
    }

    // Takes the oldest queued job, or returns null when none is left. Only
    // the worker holding the lock in runQueued() calls this.
    private static function claimNext(mysqli $db): ?array
    {
        $row = $db->query(
            "SELECT id, user_id, bgg_username FROM bgg_import_jobs WHERE status = 'queued' ORDER BY id LIMIT 1"
        )->fetch_assoc();
        if (!$row) {
            return null;
        }
        self::statement($db, "UPDATE bgg_import_jobs SET status = 'running', started_at = NOW() WHERE id = ?", 'i', [(int) $row['id']])->close();
        return $row;
    }

    // Runs one claimed job to the end, recording progress after each item.
    private static function run(mysqli $db, array $job, ?callable $getJson, int $pauseMs): void
    {
        $jobId = (int) $job['id'];
        $total = null;
        $last = [];
        try {
            $result = (new BggRatings($db, (int) $job['user_id'], $getJson))->import($job['bgg_username'], $pauseMs,
                function (array $progress, int $count) use ($db, $jobId, &$total, &$last) {
                    $total = $count;
                    $last = $progress;
                    self::record($db, $jobId, 'running', $progress, $total);
                });
        } catch (InvalidArgumentException | OutOfBoundsException | BggUnreachable $e) {
            self::record($db, $jobId, 'failed', $last, $total, $e->getMessage());
            return;
        } catch (Throwable $e) {
            // A database error's text is not for the owner.
            error_log('BGG import job ' . $jobId . ' failed: ' . $e->getMessage());
            self::record($db, $jobId, 'failed', $last, $total, 'The import hit an unexpected error.');
            return;
        }
        self::record($db, $jobId, 'done', $result, $total);
    }

    // Writes a running job's progress or outcome. A job already marked
    // failed as stale stays failed.
    private static function record(mysqli $db, int $jobId, string $status, array $result, ?int $total, ?string $error = null): void
    {
        self::statement(
            $db,
            "UPDATE bgg_import_jobs
             SET status = ?, total = ?, checked = ?, imported = ?, removed = ?, failed = ?, error = ?,
                 finished_at = IF(?, NOW(), NULL), updated_at = NOW()
             WHERE id = ? AND status = 'running'",
            'siiiiisii',
            [
                $status, $total,
                (int) ($result['checked'] ?? 0), (int) ($result['imported'] ?? 0),
                (int) ($result['removed'] ?? 0), (int) ($result['failed'] ?? 0),
                $error, in_array($status, ['done', 'failed'], true) ? 1 : 0, $jobId,
            ]
        )->close();
    }

    private static function statement(mysqli $db, string $sql, string $types, array $params): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt;
    }
}
