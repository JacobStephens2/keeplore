<?php

/**
 * A full import of the owner's BoardGameGeek reviewer, run in the background
 * because a large collection outlasts a web request. Settings queues one,
 * private/crons/run_bgg_import_jobs.php runs it each minute, and Settings
 * reads its progress back while it runs. bgg_import_jobs holds one row per
 * import.
 */

require_once __DIR__ . '/classes/BggRatings.php';

// A job the worker has not touched for this long is dead: a running one
// stopped mid-import, and a queued one was never picked up.
const BGG_IMPORT_JOB_STALE_MINUTES = 10;

// Marks dead jobs failed, so they neither block a new import nor show as
// active forever. A worker that wakes after this cannot revive its job:
// bgg_import_job_record() only writes to a running job.
function bgg_import_jobs_fail_stale($conn) {
  $stale = 'updated_at < NOW() - INTERVAL ' . BGG_IMPORT_JOB_STALE_MINUTES . ' MINUTE';
  mysqli_query(
    $conn,
    "UPDATE bgg_import_jobs
     SET status = 'failed', error = 'The import stopped before it finished.', finished_at = NOW()
     WHERE status = 'running' AND {$stale}"
  );
  // A queued job may wait behind another owner's import; it is only dead
  // when the worker shows no sign of life: nothing running, nothing just done.
  $alive = mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM bgg_import_jobs
     WHERE status = 'running' OR (status = 'done' AND finished_at > NOW() - INTERVAL " . BGG_IMPORT_JOB_STALE_MINUTES . ' MINUTE)'
  ));
  if ((int) $alive[0] === 0) {
    mysqli_query(
      $conn,
      "UPDATE bgg_import_jobs
       SET status = 'failed', error = 'It never started. The background worker may not be running.', finished_at = NOW()
       WHERE status = 'queued' AND {$stale}"
    );
  }
}

function bgg_import_job_is_active($job) {
  return $job !== null && in_array($job['status'], ['queued', 'running'], true);
}

/**
 * What Settings shows: whether an import is under way, whether the owner can
 * start one (they need a reviewer and no import under way), and the status
 * line.
 */
function bgg_import_job_view($conn, $user_id) {
  $job = bgg_import_job_latest($conn, $user_id);
  $active = bgg_import_job_is_active($job);
  return [
    'active' => $active,
    'can_queue' => !$active && (new BggRatings($conn, (int) $user_id))->ownReviewer() !== null,
    'text' => bgg_import_job_status_text($job),
  ];
}

/**
 * Queues a full import of the reviewer named on Settings. ['ok' => true,
 * 'message'] or ['ok' => false, 'error'] when there is no reviewer or an
 * import is already queued or running.
 */
function bgg_import_job_queue($conn, $user_id) {
  $user_id = (int) $user_id;
  $username = (new BggRatings($conn, $user_id))->ownReviewer();
  if ($username === null) {
    return ['ok' => false, 'error' => 'Name a BoardGameGeek reviewer above first.'];
  }
  $latest = bgg_import_job_latest($conn, $user_id);
  if (bgg_import_job_is_active($latest)) {
    return ['ok' => false, 'error' => 'An import of ' . $latest['bgg_username'] . ' is already queued or running.'];
  }
  $stmt = mysqli_prepare($conn, 'INSERT INTO bgg_import_jobs (user_id, bgg_username) VALUES (?, ?)');
  mysqli_stmt_bind_param($stmt, 'is', $user_id, $username);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  return ['ok' => true, 'message' => bgg_import_job_status_text(bgg_import_job_latest($conn, $user_id))];
}

// The owner's most recent import, or null. Counts are ints; total is null
// until the worker knows how many items it will check.
function bgg_import_job_latest($conn, $user_id) {
  bgg_import_jobs_fail_stale($conn);
  $stmt = mysqli_prepare(
    $conn,
    'SELECT id, bgg_username, status, total, checked, imported, removed, failed, error, created_at, started_at, finished_at
     FROM bgg_import_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 1'
  );
  $user_id = (int) $user_id;
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $job = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$job) {
    return null;
  }
  foreach (['id', 'checked', 'imported', 'removed', 'failed'] as $count) {
    $job[$count] = (int) $job[$count];
  }
  $job['total'] = $job['total'] === null ? null : (int) $job['total'];
  return $job;
}

// Settings' one-line account of an import.
function bgg_import_job_status_text($job) {
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

// Takes the oldest queued job, or returns null when none is left. Only the
// worker holding the lock in bgg_import_jobs_run_queued() calls this.
function bgg_import_job_claim_next($conn) {
  $row = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT id, user_id, bgg_username FROM bgg_import_jobs WHERE status = 'queued' ORDER BY id LIMIT 1"
  ));
  if (!$row) {
    return null;
  }
  $stmt = mysqli_prepare($conn, "UPDATE bgg_import_jobs SET status = 'running', started_at = NOW() WHERE id = ?");
  $id = (int) $row['id'];
  mysqli_stmt_bind_param($stmt, 'i', $id);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  return $row;
}

// Writes a running job's progress or outcome. A job already marked failed
// as stale stays failed.
function bgg_import_job_record($conn, $job_id, $status, array $result, $total, $error = null) {
  $finished = in_array($status, ['done', 'failed'], true) ? 1 : 0;
  $stmt = mysqli_prepare(
    $conn,
    "UPDATE bgg_import_jobs
     SET status = ?, total = ?, checked = ?, imported = ?, removed = ?, failed = ?, error = ?,
         finished_at = IF(?, NOW(), NULL), updated_at = NOW()
     WHERE id = ? AND status = 'running'"
  );
  $checked = (int) ($result['checked'] ?? 0);
  $imported = (int) ($result['imported'] ?? 0);
  $removed = (int) ($result['removed'] ?? 0);
  $failed = (int) ($result['failed'] ?? 0);
  mysqli_stmt_bind_param($stmt, 'siiiiisii', $status, $total, $checked, $imported, $removed, $failed, $error, $finished, $job_id);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

// Runs one claimed job to the end, recording progress after each item.
function bgg_import_job_run($conn, array $job, $get_json, $pause_ms) {
  $job_id = (int) $job['id'];
  $total = null;
  $last = [];
  try {
    $result = (new BggRatings($conn, (int) $job['user_id'], $get_json))->import($job['bgg_username'], $pause_ms,
      function (array $progress, $count) use ($conn, $job_id, &$total, &$last) {
        $total = $count;
        $last = $progress;
        bgg_import_job_record($conn, $job_id, 'running', $progress, $total);
      });
  } catch (InvalidArgumentException | OutOfBoundsException | BggUnreachable $e) {
    bgg_import_job_record($conn, $job_id, 'failed', $last, $total, $e->getMessage());
    return;
  } catch (Throwable $e) {
    // A database error's text is not for the owner.
    error_log('BGG import job ' . $job_id . ' failed: ' . $e->getMessage());
    bgg_import_job_record($conn, $job_id, 'failed', $last, $total, 'The import hit an unexpected error.');
    return;
  }
  bgg_import_job_record($conn, $job_id, 'done', $result, $total);
}

/**
 * The cron worker: runs every queued import, oldest first, one at a time so
 * BGG sees one import's requests at once. A second worker started while one
 * runs does nothing. Returns how many imports it ran.
 */
function bgg_import_jobs_run_queued($conn, $get_json = null, $pause_ms = 250) {
  $lock = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('keeplore_bgg_import_jobs', 0)"));
  if ((int) ($lock[0] ?? 0) !== 1) {
    return 0;
  }
  $ran = 0;
  try {
    bgg_import_jobs_fail_stale($conn);
    while (($job = bgg_import_job_claim_next($conn)) !== null) {
      bgg_import_job_run($conn, $job, $get_json, $pause_ms);
      $ran++;
    }
  } finally {
    mysqli_query($conn, "SELECT RELEASE_LOCK('keeplore_bgg_import_jobs')");
  }
  return $ran;
}
