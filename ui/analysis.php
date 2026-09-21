<?php
  require_once('../private/initialize.php');
  require_once(PRIVATE_PATH . '/analysis.php');
  require_login_or_guest();

  $page_title = 'Analysis';
  date_default_timezone_set('America/New_York');
  $report = analysis_report_for_user($db, (int) $_SESSION['user_id'], date('Y-m-d'));

  $totals = $report['totals'];
  $pace = $report['pace'];
  $records = $report['records'];
  $recency = $report['recency'];
  $company = $report['company'];

  function analysis_item_link(array $row) {
    $page = is_guest() ? 'show' : 'edit';
    return '<a href="' . url_for('/artifacts/' . $page . '.php?id=' . h(u($row['id']))) . '">' . h($row['title']) . '</a>';
  }

  // "+4 vs prior 30 days" style comparison line under a headline figure.
  function analysis_change(int $now, int $before, string $versus) {
    $diff = $now - $before;
    $sign = $diff > 0 ? '+' : ($diff < 0 ? '&minus;' : '&plusmn;');
    return $sign . number_format(abs($diff)) . ' vs ' . h($versus);
  }

  // Horizontal bar list: label, proportional bar, value. No script needed.
  function analysis_bar_list(array $rows) {
    $max = max(1, max(array_column($rows, 'count') ?: [0]));
    $html = '<ul class="bar-list">';
    foreach ($rows as $row) {
      $width = round($row['count'] / $max * 100, 1);
      $html .= '<li><span class="bar-list-label">' . h($row['label']) . '</span>'
        . '<span class="bar-list-track"><span class="bar-list-bar" style="width:' . $width . '%"></span></span>'
        . '<span class="bar-list-value">' . number_format($row['count']) . '</span></li>';
    }
    return $html . '</ul>';
  }

  include(SHARED_PATH . '/header.php');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>

<main class="analysis-page">
  <header class="page-header">
    <p class="section-label">Insights</p>
    <h1>Analysis</h1>
    <p class="page-lede">What you track, how often you use it, and which kept items are earning their place.</p>
    <?php if (!is_guest()) { ?>
      <p><a href="<?php echo url_for('/proposals/index.php'); ?>">Proposal outcomes — compare explicit declines and items passed over</a></p>
    <?php } ?>
  </header>

  <section class="analysis-layout">
    <div class="analysis-tiles">
      <div class="metric-card">
        <span class="metric-label">People</span>
        <strong><?php echo number_format($totals['people']); ?></strong>
        <span class="metric-note"><?php echo number_format($company['people_count']); ?> shared a use</span>
      </div>
      <div class="metric-card">
        <span class="metric-label">Items</span>
        <strong><?php echo number_format($totals['items']); ?></strong>
        <span class="metric-note"><?php echo number_format($totals['kept_items']); ?> kept</span>
      </div>
      <div class="metric-card">
        <span class="metric-label">Uses</span>
        <strong><?php echo number_format($totals['uses']); ?></strong>
        <?php if ($pace['first_use']) { ?>
          <span class="metric-note">since <?php echo h($pace['first_use']); ?></span>
        <?php } ?>
      </div>
      <div class="metric-card">
        <span class="metric-label">Last 30 days</span>
        <strong><?php echo number_format($pace['last_30']); ?></strong>
        <span class="metric-note"><?php echo analysis_change($pace['last_30'], $pace['prior_30'], 'prior 30'); ?></span>
      </div>
      <div class="metric-card">
        <span class="metric-label">This year</span>
        <strong><?php echo number_format($pace['this_year']); ?></strong>
        <span class="metric-note"><?php echo analysis_change($pace['this_year'], $pace['last_year_to_date'], 'last year to date'); ?></span>
      </div>
      <div class="metric-card">
        <span class="metric-label">Uses / week</span>
        <strong><?php echo h($pace['avg_per_week']); ?></strong>
        <span class="metric-note">over <?php echo number_format($pace['days_tracking']); ?> days</span>
      </div>
      <div class="metric-card">
        <span class="metric-label">Kept, used past year</span>
        <strong><?php echo (int) $recency['used_past_year_percent']; ?>%</strong>
        <span class="metric-note"><?php echo number_format($recency['used_past_year']); ?> of <?php echo number_format($recency['kept_items']); ?></span>
      </div>
    </div>

    <section class="menu-card analysis-card">
      <p class="section-label">Activity</p>
      <h2 class="menu-card-title">The past year, day by day</h2>
      <div class="calendar-scroll">
        <div class="calendar-months" aria-hidden="true">
          <?php
            // One label cell per week column; named when a new month starts.
            $shown_month = '';
            foreach (array_chunk($report['calendar']['days'], 7) as $week) {
              $month = date_create($week[0]['date'])->format('M');
              echo '<span>' . ($month !== $shown_month ? h($month) : '') . '</span>';
              $shown_month = $month;
            }
          ?>
        </div>
        <div class="calendar-grid" role="img" aria-label="Uses per day over the past year">
          <?php foreach ($report['calendar']['days'] as $day) {
          ?><span class="calendar-day level-<?php echo (int) $day['level']; ?>" title="<?php echo h($day['date']); ?>: <?php echo (int) $day['count']; ?> use<?php echo $day['count'] === 1 ? '' : 's'; ?>"></span><?php } ?>
        </div>
      </div>
      <p class="calendar-legend">
        Fewer
        <span class="calendar-day level-0"></span><span class="calendar-day level-1"></span><span class="calendar-day level-2"></span><span class="calendar-day level-3"></span><span class="calendar-day level-4"></span>
        More
        <?php if ($records['current_streak'] > 0) { ?>
          &middot; current streak <?php echo (int) $records['current_streak']; ?> day<?php echo $records['current_streak'] === 1 ? '' : 's'; ?>
        <?php } ?>
      </p>
    </section>

    <div class="analysis-split">
      <section class="menu-card analysis-card">
        <p class="section-label">Trend</p>
        <h2 class="menu-card-title">Uses per month, against the year before</h2>
        <div class="chart-wrap"><canvas id="chart-monthly" height="300"></canvas></div>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Records</p>
        <h2 class="menu-card-title">High-water marks</h2>
        <ul class="fun-facts">
          <?php if ($records['longest_streak']['days'] > 0) { ?>
            <li>
              <span class="fact-label">Longest daily streak</span>
              <?php echo (int) $records['longest_streak']['days']; ?> day<?php echo $records['longest_streak']['days'] === 1 ? '' : 's'; ?>
              <span class="fact-aside"><?php echo h($records['longest_streak']['start']); ?> &rarr; <?php echo h($records['longest_streak']['end']); ?></span>
            </li>
          <?php } ?>
          <?php if ($records['busiest_day']) { ?>
            <li>
              <span class="fact-label">Busiest day</span>
              <?php echo h($records['busiest_day']['date']); ?>
              <span class="fact-aside"><?php echo number_format($records['busiest_day']['count']); ?> uses</span>
            </li>
          <?php } ?>
          <?php if ($records['busiest_month']) { ?>
            <li>
              <span class="fact-label">Busiest month</span>
              <?php echo h($records['busiest_month']['month']); ?>
              <span class="fact-aside"><?php echo number_format($records['busiest_month']['count']); ?> uses</span>
            </li>
          <?php } ?>
          <?php if ($totals['uses'] > 0) { ?>
            <li>
              <span class="fact-label">Concentration</span>
              <?php echo (int) $records['top_ten_share']; ?>% of uses
              <span class="fact-aside">go to your ten most-used items</span>
            </li>
            <li>
              <span class="fact-label">Company</span>
              <?php echo number_format($company['shared_uses']); ?> shared
              <span class="fact-aside"><?php echo number_format($company['solo_uses']); ?> solo or unrecorded</span>
            </li>
          <?php } ?>
        </ul>
      </section>
    </div>

    <?php if (!empty($report['settings'])) { ?>
      <section class="menu-card analysis-card">
        <p class="section-label">Settings</p>
        <h2 class="menu-card-title">Where uses happen, and what gets used there</h2>
        <div class="settings-grid">
          <?php foreach ($report['settings'] as $setting) { ?>
            <div class="setting-column">
              <h3><?php echo h($setting['setting']); ?></h3>
              <p class="metric-note"><?php echo number_format($setting['count']); ?> use<?php echo $setting['count'] === 1 ? '' : 's'; ?></p>
              <ol>
                <?php foreach ($setting['items'] as $row) { ?>
                  <li>
                    <?php echo analysis_item_link($row); ?>
                    <span class="bar-list-value"><?php echo number_format($row['count']); ?></span>
                  </li>
                <?php } ?>
              </ol>
            </div>
          <?php } ?>
        </div>
      </section>
    <?php } ?>

    <div class="analysis-grid">
      <section class="menu-card analysis-card">
        <p class="section-label">Kept items</p>
        <h2 class="menu-card-title">When each was last used</h2>
        <?php echo analysis_bar_list($recency['buckets']); ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Neglected</p>
        <h2 class="menu-card-title">Kept items idle the longest</h2>
        <?php if (empty($report['neglected'])) { ?>
          <p class="menu-support">No kept items to weigh yet.</p>
        <?php } else { ?>
          <table class="list analysis-list">
            <thead><tr><th>Item</th><th>Since</th><th class="num">Days idle</th></tr></thead>
            <tbody>
              <?php foreach ($report['neglected'] as $row) { ?>
                <tr>
                  <td><?php echo analysis_item_link($row); ?></td>
                  <td class="nowrap"><?php echo $row['last_used'] ? 'used ' : 'acquired '; echo h($row['since']); ?></td>
                  <td class="num"><?php echo number_format($row['days_idle']); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Top 10</p>
        <h2 class="menu-card-title">Most used, last 90 days</h2>
        <?php if (empty($report['top_recent'])) { ?>
          <p class="menu-support">No uses recorded in the last 90 days.</p>
        <?php } else { ?>
          <table class="list analysis-list">
            <thead><tr><th>Item</th><th>Type</th><th class="num">Uses</th></tr></thead>
            <tbody>
              <?php foreach ($report['top_recent'] as $row) { ?>
                <tr>
                  <td><?php echo analysis_item_link($row); ?></td>
                  <td><?php echo h($row['type']); ?></td>
                  <td class="num"><?php echo number_format($row['count']); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Top 10</p>
        <h2 class="menu-card-title">Most used, all time</h2>
        <?php if (empty($report['top_all_time'])) { ?>
          <p class="menu-support">No uses recorded yet.</p>
        <?php } else { ?>
          <table class="list analysis-list">
            <thead><tr><th>Item</th><th>Last used</th><th class="num">Uses</th></tr></thead>
            <tbody>
              <?php foreach ($report['top_all_time'] as $row) { ?>
                <tr>
                  <td><?php echo analysis_item_link($row); ?></td>
                  <td><?php echo h($row['last_used'] ?? ''); ?></td>
                  <td class="num"><?php echo number_format($row['count']); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Company</p>
        <h2 class="menu-card-title">Who you share uses with</h2>
        <?php if (empty($company['people'])) { ?>
          <p class="menu-support">No shared uses recorded yet.</p>
        <?php } else { ?>
          <table class="list analysis-list">
            <thead><tr><th>Person</th><th>Last shared</th><th class="num">Uses</th></tr></thead>
            <tbody>
              <?php foreach ($company['people'] as $row) { ?>
                <tr>
                  <td>
                    <?php if (is_guest()) { echo h($row['name']); } else { ?>
                      <a href="<?php echo url_for('/users/edit.php?id=' . h(u($row['id']))); ?>"><?php echo h($row['name']); ?></a>
                    <?php } ?>
                  </td>
                  <td><?php echo h($row['last_shared'] ?? ''); ?></td>
                  <td class="num"><?php echo number_format($row['count']); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Breakdown</p>
        <h2 class="menu-card-title">Uses by type, last 90 days</h2>
        <?php if (empty($report['types'])) { ?>
          <p class="menu-support">No uses recorded in the last 90 days.</p>
        <?php } else { echo analysis_bar_list($report['types']); } ?>
      </section>

      <section class="menu-card analysis-card">
        <p class="section-label">Pattern</p>
        <h2 class="menu-card-title">Weekday rhythm, all time</h2>
        <?php echo analysis_bar_list($report['weekdays']); ?>
      </section>
    </div>
  </section>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var canvas = document.getElementById('chart-monthly');
      var monthly = <?php echo json_encode($report['monthly']); ?>;
      var chart = null;

      function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      }

      // Colors come from the theme tokens so the chart follows light/dark.
      function draw() {
        if (chart) { chart.destroy(); }
        Chart.defaults.color = token('--text-soft');
        chart = new Chart(canvas, {
          type: 'line',
          data: {
            labels: monthly.labels.map(function (label) { return label.slice(0, 3); }),
            datasets: [
              {
                label: 'Last 12 months',
                data: monthly.current,
                borderColor: token('--primary'),
                backgroundColor: token('--primary'),
                borderWidth: 2,
                tension: 0.25,
                pointRadius: 3,
              },
              {
                label: 'Year before',
                data: monthly.previous,
                borderColor: token('--secondary'),
                backgroundColor: token('--secondary'),
                borderWidth: 2,
                borderDash: [5, 4],
                tension: 0.25,
                pointRadius: 0,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'line' } } },
            scales: {
              x: { grid: { display: false } },
              y: { beginAtZero: true, grid: { color: token('--outline') }, ticks: { precision: 0 } },
            },
          },
        });
      }

      var tries = 0;
      var waiting = setInterval(function () {
        if (typeof Chart !== 'undefined') {
          clearInterval(waiting);
          draw();
          new MutationObserver(draw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        } else if (++tries > 50) {
          clearInterval(waiting);
          console.warn('Chart.js failed to load');
        }
      }, 100);
    });
  </script>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
