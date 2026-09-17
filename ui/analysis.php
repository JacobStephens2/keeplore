<?php
  require_once('../private/initialize.php');
  require_login_or_guest();

  $page_title = 'Analysis';
  $user_id = (int) $_SESSION['user_id'];
  date_default_timezone_set('America/New_York');

  // ---- Helpers ---------------------------------------------------------------

  function fetch_one($sql, $user_id) {
    global $db;
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
  }

  function fetch_all($sql, $user_id) {
    global $db;
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) { $rows[] = $row; }
    mysqli_stmt_close($stmt);
    return $rows;
  }

  // ---- Summary counts --------------------------------------------------------

  $total_uses = (int) (fetch_one("SELECT COUNT(*) AS c FROM uses WHERE user_id = ?", $user_id)['c'] ?? 0);
  $uses_7 = (int) (fetch_one("SELECT COUNT(*) AS c FROM uses WHERE user_id = ? AND use_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", $user_id)['c'] ?? 0);
  $uses_30 = (int) (fetch_one("SELECT COUNT(*) AS c FROM uses WHERE user_id = ? AND use_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $user_id)['c'] ?? 0);
  $uses_90 = (int) (fetch_one("SELECT COUNT(*) AS c FROM uses WHERE user_id = ? AND use_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)", $user_id)['c'] ?? 0);
  $tracked = (int) (fetch_one("SELECT COUNT(*) AS c FROM games WHERE user_id = ? AND is_kept = 1 AND (to_get_rid_of = 0 OR to_get_rid_of IS NULL)", $user_id)['c'] ?? 0);
  $distinct_used = (int) (fetch_one("SELECT COUNT(DISTINCT artifact_id) AS c FROM uses WHERE user_id = ?", $user_id)['c'] ?? 0);
  $first_use_row = fetch_one("SELECT MIN(use_date) AS d FROM uses WHERE user_id = ?", $user_id);
  $first_use = $first_use_row['d'] ?? null;
  $days_tracking = $first_use ? max(1, (int) ((time() - strtotime($first_use)) / 86400)) : null;
  $avg_per_week = ($days_tracking && $total_uses > 0)
    ? round(($total_uses / max($days_tracking, 1)) * 7, 1)
    : 0;

  // ---- Uses per month (last 12 months including current) ---------------------

  $monthly_rows = fetch_all(
    "SELECT DATE_FORMAT(use_date, '%Y-%m') AS month, COUNT(*) AS c
     FROM uses
     WHERE user_id = ?
       AND use_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
     GROUP BY month
     ORDER BY month",
    $user_id
  );
  $monthly_map = [];
  foreach ($monthly_rows as $r) { $monthly_map[$r['month']] = (int) $r['c']; }
  $monthly_labels = [];
  $monthly_counts = [];
  $month_cursor = new DateTime(date('Y-m-01'));
  $month_cursor->modify('-11 months');
  for ($i = 0; $i < 12; $i++) {
    $key = $month_cursor->format('Y-m');
    $monthly_labels[] = $month_cursor->format('M Y');
    $monthly_counts[] = $monthly_map[$key] ?? 0;
    $month_cursor->modify('+1 month');
  }

  // ---- Top 10 most-interacted (last 90 days) --------------------------------

  $top_recent = fetch_all(
    "SELECT games.id, games.Title, COALESCE(types.objectType, '') AS type, COUNT(uses.id) AS use_count
     FROM uses
     JOIN games ON uses.artifact_id = games.id
     LEFT JOIN types ON games.type_id = types.id
     WHERE uses.user_id = ?
       AND uses.use_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY games.id, games.Title, types.objectType
     ORDER BY use_count DESC, games.Title ASC
     LIMIT 10",
    $user_id
  );

  // ---- Uses by type (last 90 days) ------------------------------------------

  $type_rows = fetch_all(
    "SELECT COALESCE(types.objectType, '—') AS type, COUNT(uses.id) AS c
     FROM uses
     JOIN games ON uses.artifact_id = games.id
     LEFT JOIN types ON games.type_id = types.id
     WHERE uses.user_id = ?
       AND uses.use_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY types.objectType
     ORDER BY c DESC",
    $user_id
  );
  $type_labels = array_map(fn($r) => $r['type'], $type_rows);
  $type_counts = array_map(fn($r) => (int) $r['c'], $type_rows);

  // ---- Day-of-week breakdown -------------------------------------------------

  $dow_rows = fetch_all(
    "SELECT DAYOFWEEK(use_date) AS dow, COUNT(*) AS c
     FROM uses
     WHERE user_id = ?
     GROUP BY dow",
    $user_id
  );
  $dow_counts = array_fill(1, 7, 0);
  foreach ($dow_rows as $r) { $dow_counts[(int) $r['dow']] = (int) $r['c']; }
  $dow_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  $dow_values = [$dow_counts[1], $dow_counts[2], $dow_counts[3], $dow_counts[4], $dow_counts[5], $dow_counts[6], $dow_counts[7]];

  // ---- Fun facts -------------------------------------------------------------

  $top_ever = fetch_one(
    "SELECT games.Title, COUNT(uses.id) AS c
     FROM uses
     JOIN games ON uses.artifact_id = games.id
     WHERE uses.user_id = ?
     GROUP BY games.id, games.Title
     ORDER BY c DESC, games.Title ASC
     LIMIT 1",
    $user_id
  );

  $busiest_day = fetch_one(
    "SELECT use_date, COUNT(*) AS c
     FROM uses
     WHERE user_id = ?
     GROUP BY use_date
     ORDER BY c DESC, use_date DESC
     LIMIT 1",
    $user_id
  );

  $stalest = fetch_one(
    "SELECT games.Title,
        (SELECT MAX(uses.use_date) FROM uses WHERE uses.artifact_id = games.id AND uses.user_id = games.user_id) AS last_used,
        games.Acq
     FROM games
     WHERE games.user_id = ?
       AND games.is_kept = 1
       AND (games.to_get_rid_of = 0 OR games.to_get_rid_of IS NULL)
     ORDER BY COALESCE(
       (SELECT MAX(uses.use_date) FROM uses WHERE uses.artifact_id = games.id AND uses.user_id = games.user_id),
       games.Acq
     ) ASC
     LIMIT 1",
    $user_id
  );

  // Longest run of consecutive days with at least one interaction
  $distinct_days = fetch_all(
    "SELECT DISTINCT use_date FROM uses WHERE user_id = ? ORDER BY use_date",
    $user_id
  );
  $longest_streak = 0; $current_streak = 0; $streak_end = null; $streak_start = null;
  $best_start = null; $best_end = null;
  $prev_ts = null;
  foreach ($distinct_days as $row) {
    $d = $row['use_date'];
    $ts = strtotime($d);
    if ($prev_ts !== null && ($ts - $prev_ts) === 86400) {
      $current_streak++;
    } else {
      $current_streak = 1;
      $streak_start = $d;
    }
    $streak_end = $d;
    if ($current_streak > $longest_streak) {
      $longest_streak = $current_streak;
      $best_start = $streak_start;
      $best_end = $streak_end;
    }
    $prev_ts = $ts;
  }

  include(SHARED_PATH . '/header.php');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>

<main>
  <header class="page-header">
    <p class="section-label">Insights</p>
    <h1>Analysis</h1>
    <p class="page-lede">How often you interact, what leads the list, and where the queue is thin.</p>
    <?php if (!is_guest()) { ?>
      <p><a href="<?php echo url_for('/proposals/index.php'); ?>">Proposal outcomes — compare explicit declines and items passed over</a></p>
    <?php } ?>
  </header>
  <div class="dashboard">
    <section class="dashboard-hero">
      <div class="dashboard-hero-copy">
        <p class="section-label">Insights</p>
        <h1>Analysis</h1>
        <p class="dashboard-intro">Trends and fun facts from your interaction history.</p>
      </div>
      <div class="dashboard-hero-aside">
        <div class="metric-card">
          <span class="metric-label">Total interactions</span>
          <strong><?php echo number_format($total_uses); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Last 7 days</span>
          <strong><?php echo number_format($uses_7); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Last 30 days</span>
          <strong><?php echo number_format($uses_30); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Last 90 days</span>
          <strong><?php echo number_format($uses_90); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Tracked items</span>
          <strong><?php echo number_format($tracked); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Distinct ever used</span>
          <strong><?php echo number_format($distinct_used); ?></strong>
        </div>
      </div>
    </section>

    <section class="menu-card analysis-card">
      <p class="section-label">Trend</p>
      <h2 class="menu-card-title">Interactions per month</h2>
      <p class="menu-support">Last 12 months of recorded interactions.</p>
      <div class="chart-wrap"><canvas id="chart-monthly" height="260"></canvas></div>
    </section>

    <div class="analysis-grid">
      <section class="menu-card analysis-card">
        <p class="section-label">Top 10</p>
        <h2 class="menu-card-title">Most-interacted (last 90 days)</h2>
        <?php if (empty($top_recent)) { ?>
          <p class="menu-support">No interactions recorded in the last 90 days.</p>
        <?php } else { ?>
          <table class="list analysis-list">
            <thead>
              <tr>
                <th>Item</th>                <th>Type</th>
                <th class="num">Uses</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($top_recent as $row) { ?>
                <tr>
                  <td>
                    <a href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($row['id']))); ?>">
                      <?php echo h($row['Title']); ?>
                    </a>
                  </td>
                  <td><?php echo h($row['type']); ?></td>
                  <td class="num"><?php echo (int) $row['use_count']; ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Breakdown</p>
        <h2 class="menu-card-title">By type (last 90 days)</h2>
        <?php if (empty($type_rows)) { ?>
          <p class="menu-support">No interactions recorded in the last 90 days.</p>
        <?php } else { ?>
          <div class="chart-wrap"><canvas id="chart-types" height="260"></canvas></div>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Pattern</p>
        <h2 class="menu-card-title">Day-of-week rhythm</h2>
        <p class="menu-support">All recorded interactions, grouped by weekday.</p>
        <div class="chart-wrap"><canvas id="chart-dow" height="260"></canvas></div>
      </section>
    </div>

    <section class="menu-card analysis-card">
      <p class="section-label">Fun facts</p>
      <h2 class="menu-card-title">Anything jump out?</h2>
      <ul class="fun-facts">
        <?php if ($first_use) { ?>
          <li>
            <span class="fact-label">Tracking since</span>
            <?php echo h($first_use); ?>
            <span class="fact-aside">(<?php echo number_format($days_tracking); ?> days)</span>
          </li>
        <?php } ?>
        <?php if ($avg_per_week > 0) { ?>
          <li>
            <span class="fact-label">Average interactions / week</span>
            <?php echo $avg_per_week; ?>
          </li>
        <?php } ?>
        <?php if (!empty($top_ever)) { ?>
          <li>
            <span class="fact-label">Most-interacted ever</span>
            <?php echo h($top_ever['Title']); ?>
            <span class="fact-aside">(<?php echo (int) $top_ever['c']; ?> uses)</span>
          </li>
        <?php } ?>
        <?php if (!empty($busiest_day)) { ?>
          <li>
            <span class="fact-label">Busiest day</span>
            <?php echo h($busiest_day['use_date']); ?>
            <span class="fact-aside">(<?php echo (int) $busiest_day['c']; ?> interactions)</span>
          </li>
        <?php } ?>
        <?php if ($longest_streak > 0) { ?>
          <li>
            <span class="fact-label">Longest daily streak</span>
            <?php echo $longest_streak; ?> day<?php echo $longest_streak === 1 ? '' : 's'; ?>
            <?php if ($best_start) { ?>
              <span class="fact-aside">(<?php echo h($best_start); ?> &rarr; <?php echo h($best_end); ?>)</span>
            <?php } ?>
          </li>
        <?php } ?>
        <?php
          $most_active_dow = null; $most_active_count = 0;
          for ($i = 0; $i < 7; $i++) {
            if ($dow_values[$i] > $most_active_count) {
              $most_active_count = $dow_values[$i];
              $most_active_dow = $dow_labels[$i];
            }
          }
          if ($most_active_dow && $most_active_count > 0) {
        ?>
          <li>
            <span class="fact-label">Most-active weekday</span>
            <?php echo h($most_active_dow); ?>
            <span class="fact-aside">(<?php echo $most_active_count; ?> interactions)</span>
          </li>
        <?php } ?>
        <?php if (!empty($stalest)) { ?>
          <li>
            <span class="fact-label">Hasn't been touched the longest</span>
            <?php echo h($stalest['Title']); ?>
            <?php
              $marker = $stalest['last_used'] ?: $stalest['Acq'];
              if ($marker) {
                $days = max(0, (int) ((time() - strtotime($marker)) / 86400));
                echo '<span class="fact-aside">(' . ($stalest['last_used'] ? 'last used ' : 'acquired ') . h(substr($marker, 0, 10)) . ', ' . number_format($days) . ' days ago)</span>';
              }
            ?>
          </li>
        <?php } ?>
      </ul>
    </section>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      function whenChartReady(cb) {
        if (typeof Chart !== 'undefined') { cb(); return; }
        var tries = 0;
        var t = setInterval(function () {
          if (typeof Chart !== 'undefined') {
            clearInterval(t); cb();
          } else if (++tries > 50) {
            clearInterval(t);
            console.warn('Chart.js failed to load');
          }
        }, 100);
      }

      whenChartReady(function () {
        var primary = '#1a2345';
        var primarySoft = 'rgba(26, 35, 69, 0.55)';
        var grid = 'rgba(80, 95, 118, 0.12)';

        var monthly = document.getElementById('chart-monthly');
        if (monthly) {
          new Chart(monthly, {
            type: 'line',
            data: {
              labels: <?php echo json_encode($monthly_labels); ?>,
              datasets: [{
                label: 'Interactions',
                data: <?php echo json_encode($monthly_counts); ?>,
                borderColor: primary,
                backgroundColor: primarySoft,
                fill: true,
                tension: 0.25,
                pointRadius: 4,
                pointBackgroundColor: primary,
              }],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                x: { grid: { color: grid } },
                y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } },
              },
            },
          });
        }

        var types = document.getElementById('chart-types');
        if (types) {
          new Chart(types, {
            type: 'bar',
            data: {
              labels: <?php echo json_encode($type_labels); ?>,
              datasets: [{
                label: 'Interactions',
                data: <?php echo json_encode($type_counts); ?>,
                backgroundColor: primary,
              }],
            },
            options: {
              indexAxis: 'y',
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                x: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } },
                y: { grid: { display: false } },
              },
            },
          });
        }

        var dow = document.getElementById('chart-dow');
        if (dow) {
          new Chart(dow, {
            type: 'bar',
            data: {
              labels: <?php echo json_encode($dow_labels); ?>,
              datasets: [{
                label: 'Interactions',
                data: <?php echo json_encode($dow_values); ?>,
                backgroundColor: primary,
              }],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } },
              },
            },
          });
        }
      });
    });
  </script>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
