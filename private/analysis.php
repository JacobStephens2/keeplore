<?php

/**
 * Analysis seam: every figure the /analysis page shows.
 *
 * - analysis_report(): pure. One user's plain rows plus an explicit "today"
 *   in, the whole report out. The page only renders what it returns.
 * - analysis_report_for_user(): the database adapter. Loads the rows and
 *   hands them to analysis_report().
 */

require_once __DIR__ . '/kept_status.php';

const ANALYSIS_RECENT_DAYS = 90;
const ANALYSIS_LIST_LENGTH = 10;
const ANALYSIS_SETTINGS_SHOWN = 6;
const ANALYSIS_ITEMS_PER_SETTING = 5;

function analysis_report(array $data, string $today) {
  $items = $data['items'] ?? [];
  $uses = $data['uses'] ?? [];
  $people = $data['people'] ?? [];
  $today_n = analysis_day_number($today);

  // Uses per day number, up to today. Undated uses count only toward totals;
  // future-dated ones have not happened yet and stay off every timeline.
  $per_day = [];
  foreach ($uses as $use) {
    $n = analysis_use_day($use);
    if ($n !== null && $n <= $today_n) {
      $per_day[$n] = ($per_day[$n] ?? 0) + 1;
    }
  }
  ksort($per_day);

  $item_stats = analysis_item_stats($items, $uses, $today_n);
  $records = analysis_records($per_day, $today_n);
  $records['top_ten_share'] = analysis_top_ten_share($item_stats);

  return [
    'totals' => [
      // The player standing for the user is not someone they track.
      'people' => count(array_filter($people, fn($person) => empty($person['represents_user_id']))),
      'items' => count($items),
      'kept_items' => count(array_filter($items, 'artifact_is_kept')),
      'uses' => count($uses),
    ],
    'pace' => analysis_pace($per_day, $today),
    'monthly' => analysis_monthly($per_day, $today),
    'calendar' => analysis_calendar($per_day, $today_n),
    'weekdays' => analysis_weekdays($per_day),
    'top_recent' => analysis_top_items($item_stats, 'recent_count'),
    'top_all_time' => analysis_top_items($item_stats, 'count'),
    'types' => analysis_types($item_stats),
    'recency' => analysis_recency($item_stats, $today_n),
    'neglected' => analysis_neglected($item_stats, $today_n),
    'settings' => analysis_settings($uses, $item_stats),
    'company' => analysis_company($uses, $people, $data['participations'] ?? []),
    'records' => $records,
  ];
}

// Days since the Unix epoch for a Y-m-d date. Whole-day arithmetic that
// daylight-saving changes cannot skew.
function analysis_day_number(string $date) {
  return intdiv(strtotime(substr($date, 0, 10) . ' UTC'), 86400);
}

// Day number of a use, or null when it has no real date (NULL, '', or a
// zero date MySQL let through).
function analysis_use_day(array $use) {
  $date = substr((string) ($use['use_date'] ?? ''), 0, 10);
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
      || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
    return null;
  }
  return analysis_day_number($date);
}

function analysis_date_from_day_number(int $n) {
  return gmdate('Y-m-d', $n * 86400);
}

// 0 = Sunday ... 6 = Saturday. Day 0 (1970-01-01) was a Thursday.
function analysis_weekday(int $n) {
  return ($n + 4) % 7;
}

function analysis_count_between(array $per_day, int $from_n, int $to_n) {
  $count = 0;
  foreach ($per_day as $n => $c) {
    if ($n >= $from_n && $n <= $to_n) {
      $count += $c;
    }
  }
  return $count;
}

function analysis_pace(array $per_day, string $today) {
  $today_n = analysis_day_number($today);
  $year = (int) substr($today, 0, 4);
  $last_year_today = ($year - 1) . substr($today, 4);
  if (!checkdate((int) substr($today, 5, 2), (int) substr($today, 8, 2), $year - 1)) {
    $last_year_today = ($year - 1) . '-02-28';
  }

  $first_n = $per_day ? array_key_first($per_day) : null;
  $days_tracking = $first_n === null ? 0 : $today_n - $first_n + 1;

  return [
    'last_30' => analysis_count_between($per_day, $today_n - 29, $today_n),
    'prior_30' => analysis_count_between($per_day, $today_n - 59, $today_n - 30),
    'this_year' => analysis_count_between($per_day, analysis_day_number($year . '-01-01'), $today_n),
    'last_year_to_date' => analysis_count_between(
      $per_day,
      analysis_day_number(($year - 1) . '-01-01'),
      analysis_day_number($last_year_today)
    ),
    'first_use' => $first_n === null ? null : analysis_date_from_day_number($first_n),
    'days_tracking' => $days_tracking,
    'avg_per_week' => $days_tracking > 0 ? round(array_sum($per_day) / $days_tracking * 7, 1) : 0.0,
  ];
}

// Two aligned 12-month series: the months ending with today's, and the same
// months a year earlier.
function analysis_monthly(array $per_day, string $today) {
  $per_month = [];
  foreach ($per_day as $n => $c) {
    $month = substr(analysis_date_from_day_number($n), 0, 7);
    $per_month[$month] = ($per_month[$month] ?? 0) + $c;
  }

  $labels = [];
  $current = [];
  $previous = [];
  $cursor = new DateTimeImmutable(substr($today, 0, 7) . '-01');
  for ($back = 11; $back >= 0; $back--) {
    $month = $cursor->modify("-$back months");
    $labels[] = $month->format('M Y');
    $current[] = $per_month[$month->format('Y-m')] ?? 0;
    $previous[] = $per_month[$month->modify('-12 months')->format('Y-m')] ?? 0;
  }
  return ['labels' => $labels, 'current' => $current, 'previous' => $previous];
}

// One entry per day from the Sunday 52 weeks before this week's Sunday
// through today, so the page can lay the days out as week columns.
function analysis_calendar(array $per_day, int $today_n) {
  $start_n = $today_n - analysis_weekday($today_n) - 52 * 7;
  $max = 0;
  for ($n = $start_n; $n <= $today_n; $n++) {
    $max = max($max, $per_day[$n] ?? 0);
  }
  $days = [];
  for ($n = $start_n; $n <= $today_n; $n++) {
    $count = $per_day[$n] ?? 0;
    $days[] = [
      'date' => analysis_date_from_day_number($n),
      'count' => $count,
      // 0 for no uses, then 1-4 scaled against the busiest day shown.
      'level' => $count > 0 ? (int) ceil($count / $max * 4) : 0,
    ];
  }
  return ['days' => $days];
}

function analysis_weekdays(array $per_day) {
  $rows = [];
  foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $label) {
    $rows[] = ['label' => $label, 'count' => 0];
  }
  foreach ($per_day as $n => $c) {
    $rows[analysis_weekday($n)]['count'] += $c;
  }
  return $rows;
}

function analysis_records(array $per_day, int $today_n) {
  $busiest_day = null;
  $per_month = [];
  $longest = ['days' => 0, 'start' => null, 'end' => null];
  $run_start = null;
  $run_end = null;
  $prev_n = null;
  foreach ($per_day as $n => $c) {
    if ($busiest_day === null || $c >= $busiest_day['count']) {
      $busiest_day = ['date' => analysis_date_from_day_number($n), 'count' => $c];
    }
    $month = substr(analysis_date_from_day_number($n), 0, 7);
    $per_month[$month] = ($per_month[$month] ?? 0) + $c;

    if ($prev_n === null || $n !== $prev_n + 1) {
      $run_start = $n;
    }
    $run_end = $n;
    if ($run_end - $run_start + 1 > $longest['days']) {
      $longest = [
        'days' => $run_end - $run_start + 1,
        'start' => analysis_date_from_day_number($run_start),
        'end' => analysis_date_from_day_number($run_end),
      ];
    }
    $prev_n = $n;
  }

  // The latest run is still alive if it reaches today or yesterday.
  $current_streak = ($run_end !== null && $run_end >= $today_n - 1)
    ? $run_end - $run_start + 1
    : 0;

  $busiest_month = null;
  foreach ($per_month as $month => $c) {
    if ($busiest_month === null || $c >= $busiest_month['count']) {
      $busiest_month = ['month' => date_create($month . '-01')->format('M Y'), 'count' => $c];
    }
  }

  return [
    'busiest_day' => $busiest_day,
    'busiest_month' => $busiest_month,
    'longest_streak' => $longest,
    'current_streak' => $current_streak,
  ];
}

// Per-item use tallies keyed by item id. Uses of items that no longer exist
// or that are future-dated are left out; undated uses count without a date.
function analysis_item_stats(array $items, array $uses, int $today_n) {
  $stats = [];
  foreach ($items as $item) {
    $stats[(int) $item['id']] = [
      'id' => (int) $item['id'],
      'title' => (string) ($item['Title'] ?? ''),
      'type' => (string) ($item['type'] ?? ''),
      'is_kept' => artifact_is_kept($item),
      'to_get_rid_of' => !empty($item['to_get_rid_of']),
      'acq_n' => empty($item['Acq']) ? null : analysis_day_number($item['Acq']),
      'count' => 0,
      'recent_count' => 0,
      'last_used_n' => null,
    ];
  }
  foreach ($uses as $use) {
    $id = (int) ($use['artifact_id'] ?? 0);
    if (!isset($stats[$id])) {
      continue;
    }
    $n = analysis_use_day($use);
    if ($n !== null && $n > $today_n) {
      continue;
    }
    $stats[$id]['count']++;
    if ($n === null) {
      continue;
    }
    if ($n > $today_n - ANALYSIS_RECENT_DAYS) {
      $stats[$id]['recent_count']++;
    }
    if ($stats[$id]['last_used_n'] === null || $n > $stats[$id]['last_used_n']) {
      $stats[$id]['last_used_n'] = $n;
    }
  }
  return $stats;
}

function analysis_top_items(array $item_stats, string $count_key) {
  $used = array_filter($item_stats, fn($item) => $item[$count_key] > 0);
  usort($used, fn($a, $b) => [$b[$count_key], $a['title']] <=> [$a[$count_key], $b['title']]);

  $top = [];
  foreach (array_slice($used, 0, ANALYSIS_LIST_LENGTH) as $item) {
    $top[] = [
      'id' => $item['id'],
      'title' => $item['title'],
      'type' => $item['type'],
      'count' => $item[$count_key],
      'last_used' => $item['last_used_n'] === null
        ? null
        : analysis_date_from_day_number($item['last_used_n']),
    ];
  }
  return $top;
}

function analysis_top_ten_share(array $item_stats) {
  $counts = array_column($item_stats, 'count');
  rsort($counts);
  $total = array_sum($counts);
  return $total > 0 ? (int) round(array_sum(array_slice($counts, 0, ANALYSIS_LIST_LENGTH)) / $total * 100) : 0;
}

function analysis_types(array $item_stats) {
  $per_type = [];
  foreach ($item_stats as $item) {
    if ($item['recent_count'] > 0) {
      $type = $item['type'] === '' ? '-' : $item['type'];
      $per_type[$type] = ($per_type[$type] ?? 0) + $item['recent_count'];
    }
  }
  arsort($per_type);
  $types = [];
  foreach ($per_type as $type => $count) {
    $types[] = ['label' => (string) $type, 'count' => $count];
  }
  return $types;
}

// How recently each kept item was last used: the core "is it earning its
// place" question.
function analysis_recency(array $item_stats, int $today_n) {
  $buckets = [
    'Last 30 days' => 0,
    '31-90 days' => 0,
    '91-365 days' => 0,
    'Over a year' => 0,
    'Never used' => 0,
  ];
  $kept_items = 0;
  foreach ($item_stats as $item) {
    if (!$item['is_kept']) {
      continue;
    }
    $kept_items++;
    if ($item['last_used_n'] === null) {
      $buckets['Never used']++;
      continue;
    }
    $idle = $today_n - $item['last_used_n'];
    // Same windows as the pace figures: today is day one of the 30.
    if ($idle < 30) {
      $buckets['Last 30 days']++;
    } elseif ($idle < ANALYSIS_RECENT_DAYS) {
      $buckets['31-90 days']++;
    } elseif ($idle < 365) {
      $buckets['91-365 days']++;
    } else {
      $buckets['Over a year']++;
    }
  }

  $rows = [];
  foreach ($buckets as $label => $count) {
    $rows[] = ['label' => $label, 'count' => $count];
  }
  $used_past_year = $buckets['Last 30 days'] + $buckets['31-90 days'] + $buckets['91-365 days'];
  return [
    'buckets' => $rows,
    'kept_items' => $kept_items,
    'used_past_year' => $used_past_year,
    'used_past_year_percent' => $kept_items > 0 ? (int) round($used_past_year / $kept_items * 100) : 0,
  ];
}

// Kept items idle the longest, measured from last use or, if never used,
// from acquisition. Items already flagged to get rid of are decided; skip them.
function analysis_neglected(array $item_stats, int $today_n) {
  $idle = [];
  foreach ($item_stats as $item) {
    $since_n = $item['last_used_n'] ?? $item['acq_n'];
    if (!$item['is_kept'] || $item['to_get_rid_of'] || $since_n === null) {
      continue;
    }
    $idle[] = [
      'id' => $item['id'],
      'title' => $item['title'],
      'last_used' => $item['last_used_n'] === null ? null : analysis_date_from_day_number($item['last_used_n']),
      'since' => analysis_date_from_day_number($since_n),
      'days_idle' => max(0, $today_n - $since_n),
    ];
  }
  usort($idle, fn($a, $b) => [$b['days_idle'], $a['title']] <=> [$a['days_idle'], $b['title']]);
  return array_slice($idle, 0, ANALYSIS_LIST_LENGTH);
}

// Who the user shares uses with. The player standing for the user is not
// company; duplicate junction rows count once per use.
function analysis_company(array $uses, array $people, array $participations) {
  $use_dates = [];
  foreach ($uses as $use) {
    $n = analysis_use_day($use);
    $use_dates[(int) $use['id']] = $n === null ? null : analysis_date_from_day_number($n);
  }
  $company = [];
  foreach ($people as $person) {
    if (empty($person['represents_user_id'])) {
      $company[(int) $person['id']] = [
        'id' => (int) $person['id'],
        'name' => trim(($person['FirstName'] ?? '') . ' ' . ($person['LastName'] ?? '')),
        'uses' => [],
      ];
    }
  }

  $shared = [];
  foreach ($participations as $row) {
    $use_id = (int) ($row['use_id'] ?? 0);
    $player_id = (int) ($row['player_id'] ?? 0);
    if (!array_key_exists($use_id, $use_dates) || !isset($company[$player_id])) {
      continue;
    }
    $company[$player_id]['uses'][$use_id] = $use_dates[$use_id];
    $shared[$use_id] = true;
  }

  $ranked = [];
  foreach ($company as $person) {
    if ($person['uses']) {
      $dates = array_filter($person['uses']);
      $ranked[] = [
        'id' => $person['id'],
        'name' => $person['name'],
        'count' => count($person['uses']),
        'last_shared' => $dates ? max($dates) : null,
      ];
    }
  }
  usort($ranked, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

  return [
    'people' => array_slice($ranked, 0, ANALYSIS_LIST_LENGTH),
    'people_count' => count($ranked),
    'shared_uses' => count($shared),
    'solo_uses' => count($uses) - count($shared),
  ];
}

// Where uses happen (the use's "Setting" field, stored as uses.note), busiest
// first, each with the items most used there. Spellings that differ only by
// case or surrounding space are one setting, shown as first typed.
function analysis_settings(array $uses, array $item_stats) {
  $settings = [];
  foreach ($uses as $use) {
    $name = trim((string) ($use['note'] ?? ''));
    if ($name === '') {
      continue;
    }
    $key = mb_strtolower($name);
    if (!isset($settings[$key])) {
      $settings[$key] = ['setting' => $name, 'count' => 0, 'items' => []];
    }
    $settings[$key]['count']++;
    $id = (int) ($use['artifact_id'] ?? 0);
    if (isset($item_stats[$id])) {
      $settings[$key]['items'][$id] = ($settings[$key]['items'][$id] ?? 0) + 1;
    }
  }
  usort($settings, fn($a, $b) => [$b['count'], $a['setting']] <=> [$a['count'], $b['setting']]);

  $ranked = [];
  foreach (array_slice($settings, 0, ANALYSIS_SETTINGS_SHOWN) as $setting) {
    $items = [];
    foreach ($setting['items'] as $id => $count) {
      $items[] = ['id' => $id, 'title' => $item_stats[$id]['title'], 'count' => $count];
    }
    usort($items, fn($a, $b) => [$b['count'], $a['title']] <=> [$a['count'], $b['title']]);
    $setting['items'] = array_slice($items, 0, ANALYSIS_ITEMS_PER_SETTING);
    $ranked[] = $setting;
  }
  return $ranked;
}

// ---- Database adapter -------------------------------------------------------

function analysis_fetch_all(mysqli $db, string $sql, int $user_id) {
  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
  return $rows;
}

function analysis_report_for_user(mysqli $db, int $user_id, string $today) {
  return analysis_report([
    'uses' => analysis_fetch_all($db,
      "SELECT id, artifact_id, use_date, note FROM uses WHERE user_id = ? ORDER BY id", $user_id),
    'items' => analysis_fetch_all($db,
      "SELECT games.id, games.Title, COALESCE(types.objectType, '') AS type,
              games.is_kept, games.to_get_rid_of, games.Acq
       FROM games
       LEFT JOIN types ON games.type_id = types.id
       WHERE games.user_id = ?", $user_id),
    'people' => analysis_fetch_all($db,
      "SELECT id, FirstName, LastName, represents_user_id FROM players WHERE user_id = ?", $user_id),
    'participations' => analysis_fetch_all($db,
      "SELECT use_id, player_id FROM uses_players WHERE user_id = ?", $user_id),
  ], $today);
}
