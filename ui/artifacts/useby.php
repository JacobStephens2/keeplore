<?php  // require login
  require_once('../../private/initialize.php');
  require_login_or_guest();
?>

<?php // load header
  $page_title = 'Interact By';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html'); 
?>
<script defer src="/shared/filter_button.js"></script>
<script defer src="useby.js?v=8"></script>

<?php // process form submission and initialize variables
  require_once PRIVATE_PATH . '/interact_by.php';
  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);

  $user_id = $_SESSION['user_id'];
  $default_use_interval = (new Preferences($db, (int) $user_id))->get()['default_use_interval'];
  $filters = interact_by_filters_from_request($_SERVER['REQUEST_METHOD'], $_POST, $_SESSION, $default_use_interval);
  $entries = (new UseByQueue($db, (int) $user_id))->entries(interact_by_queue_options($filters, $type_filter['selected']));
  $rows = array_map('interact_by_present_row', $entries);
  $table = interact_by_table($filters, is_guest());
  $total_overdue = count(array_filter($rows, fn (array $row) => $row['overdue']));
?>

<main class="useby-page">

  <header class="page-header page-header-row">
    <div>
      <p class="section-label">Queue</p>
      <h1>
        <a class="hideOnPrint" target="_blank"
          href="<?php echo url_for('/artifacts/about-useby.php'); ?>"
          >
          Interact by date
        </a>
      </h1>
      <p class="page-lede hideOnPrint">What is due next, ordered so you use what you keep.</p>
    </div>
    <div class="page-header-actions">
      <?php if (!is_guest()) { ?><button id="send_use_email" data-userid="<?php echo $user_id; ?>">Send interact email</button><?php } ?>
      <div id="view_toggle" class="view-toggle" role="group" aria-label="View mode">
        <button type="button" class="view-toggle-btn" data-view="table" aria-pressed="false">Table</button>
        <button type="button" class="view-toggle-btn" data-view="cards" aria-pressed="false">Cards</button>
      </div>
      <button type="button" id="display_filters">Show filters</button>
    </div>
  </header>

  <form class="filter-panel" action="<?php echo url_for('/artifacts/useby.php'); ?>"
    id="useby-filters"
    method="post"
    style="display: none"
    >
    <?php echo csrf_input(); ?>
    <div class="hideOnPrint">

      <label for="artifactType">Item type</label>
      <section id="artifactType" class="type-chip-group">
        <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
      </section>

      <label for="sweetSpot">Sweet Spot</label>
      <input type="number" name="sweetSpot" id="sweetSpot" value="<?php echo h($filters['sweetSpot']); ?>">

      <label for="minimumAge">Minimum Age</label>
      <input type="number" name="minimumAge" id="minimumAge" value="<?php echo h($filters['minimumAge']); ?>">
      
      <label for="shelfSort">Shelf Sort (Instead of Interact By Sort)</label>
      <input type="hidden" name="shelfSort" value="no">
      <input type="checkbox" name="shelfSort" id="shelfSort" value="yes"
        <?php 
          if ($filters['shelfSort'] === 'yes') {
            echo ' checked ';
          }
        ?>
      >
      
      <label for="showAttributes">Show item attributes</label>
      <input type="hidden" name="showAttributes" value="no">
      <input type="checkbox" name="showAttributes" id="showAttributes" value="yes"
        <?php
          if ($filters['showAttributes'] === 'yes') {
            echo ' checked ';
          }
        ?>
      >

      <label for="showInterval">Show interval column</label>
      <input type="hidden" name="showInterval" value="no">
      <input type="checkbox" name="showInterval" id="showInterval" value="yes"
        <?php
          if ($filters['showInterval'] === 'yes') {
            echo ' checked ';
          }
        ?>
      >

      <label for="hideSnoozed">Hide snoozed items</label>
      <input type="hidden" name="hideSnoozed" value="no">
      <input type="checkbox" name="hideSnoozed" id="hideSnoozed" value="yes"
        <?php
          if ($filters['hideSnoozed'] === 'yes') {
            echo ' checked ';
          }
        ?>
      >

    </div>

    <div class="displayOnPrint">
      <label for="interval">Interval in days from most recent or to upcoming use</label>
      <input type="number" step="0.1" name="interval" id="interval" value="<?php echo h((string) $filters['interval']); ?>">
    </div>
    
    <input type="submit" value="Submit" class="hideOnPrint"/>
  
    <section id="legend">
      <p>U stands for used at recommended user count or used fully through at non-recommended count</p>
    </section>
  </form>

  <p class="copied_message" style="display: none"></p>
  <div id="useby-toast" class="toast" role="status" aria-live="polite"></div>

  <?php if (!is_guest()) { ?>
  <?php include(SHARED_PATH . '/quick_record_popup.php'); ?>
  <?php } ?>

  <div class="table-scroll">
  <table id="useBy" class="list" data-page-length='100' data-order="<?php echo h(json_encode($table['order'])); ?>">
    <thead>
      <tr id="headerRow">
        <?php foreach ($table['columns'] as $column) {
          echo match ($column) {
            'name' => '<th>Name (' . count($rows) . ')</th>',
            'use_by_date' => '<th>Interact By</th>',
            'record' => '<th>Record</th>',
            'type' => '<th>Type</th>',
            'sws' => '<th>SwS</th>',
            'avg_time' => '<th>AvgT</th>',
            'age' => '<th>Age</th>',
            'ss' => "<th>SwS's</th>",
            'mnp' => '<th>MnP</th>',
            'mxp' => '<th>MxP</th>',
            'candidate' => '<th>C</th>',
            'get_rid_of' => '<th class="hideOnPrint">Get Rid Of</th>',
            'overdue' => '<th>Overdue (<span id="totalOverdue">' . $total_overdue . '</span>)</th>',
            'last_use' => '<th class="hideOnPrint">Recent Interaction</th>',
            'acq' => '<th>Tracking Start</th>',
            'interval' => '<th>Interval</th>',
          };
        } ?>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($rows as $row) {
        $id = h(u($row['id']));
        ?>
        <tr>
          <?php foreach ($table['columns'] as $column) {
            switch ($column) {
              case 'name': ?>
          <td class="name artifact edit" data-label="Name">
            <div>
              <a id="artifact_id_<?php echo $id; ?>"
                class="action edit"
                href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . $id); ?>"
                ><?php echo h($row['title']);
              ?></a>
              <img class="clipboard"
                id="artifact_id_copy_<?php echo $id; ?>"
                src="/assets/copy.png"
                alt="A clipboard icon for copying"
              >
              <?php if ($row['is_snoozed']) { ?>
                <span class="snoozed-badge" title="Hidden from the dashboard priority queue until this date">Snoozed until <?php echo h($row['snoozed_until']); ?></span>
              <?php } ?>

              <script>
                document
                  .querySelector('img#artifact_id_copy_<?php echo $id; ?>')
                  .addEventListener('click', function() {
                    let text = document.querySelector('a#artifact_id_<?php echo $id; ?>').innerHTML;
                    navigator.clipboard.writeText(text);
                    var copied_message = document.querySelector('p.copied_message');
                    copied_message.innerText = text + ' copied';
                    copied_message.style.display = 'block';
                    setTimeout(() => {
                      copied_message.innerText = '';
                      copied_message.style.display = 'none';
                    }, 1500);
                  }
                );

              </script>
            </div>
          </td>
              <?php break;
              case 'use_by_date': ?>
          <td class="useByDate date<?php if ($row['overdue']) echo ' overdue-past'; ?>" data-label="Interact by"><?php echo h($row['use_by_date']); ?></td>
              <?php break;
              case 'record': ?>
          <td class="record" data-label="Record">
            <a href="/uses/record-new?artifact_id=<?php echo $id; ?>"
              target="_blank"
              >
              Record
            </a>
          </td>
              <?php break;
              case 'type': ?>
          <td class="type" data-label="Type"><?php echo h($row['type']); ?></td>
              <?php break;
              case 'sws': ?>
          <td class="SwS" data-label="SwS"><?php echo h((string) $row['sws']); ?></td>
              <?php break;
              case 'avg_time': ?>
          <td class="AvgT" data-label="AvgT"><?php echo h((string) $row['avg_time']); ?></td>
              <?php break;
              case 'age': ?>
          <td class="Age" data-label="Age"><?php echo h((string) $row['age']); ?></td>
              <?php break;
              case 'ss': ?>
          <td class="SwSs" data-label="SwS's"><?php echo h($row['ss']); ?></td>
              <?php break;
              case 'mnp': ?>
          <td class="MnP" data-label="MnP"><?php echo h($row['mnp']); ?></td>
              <?php break;
              case 'mxp': ?>
          <td class="MxP" data-label="MxP"><?php echo h($row['mxp']); ?></td>
              <?php break;
              case 'candidate': ?>
          <td class="candidate" data-label="Candidate"><?php if ($row['candidate']) echo 'Yes'; ?></td>
              <?php break;
              case 'get_rid_of': ?>
          <td class="get-rid-of hideOnPrint" data-label="Actions">
            <form method="post" action="<?php echo url_for('/artifacts/mark-get-rid-of.php'); ?>" class="get-rid-of-form" style="margin:0;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
              <input type="hidden" name="artifact_name" value="<?php echo h($row['title']); ?>">
              <input type="hidden" name="return_to" value="useby">
              <button type="submit" class="get-rid-of-btn">Get Rid Of</button>
            </form>
            <form method="post" action="<?php echo url_for('/artifacts/set-tracked.php'); ?>" class="untrack-form" style="margin:0;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
              <input type="hidden" name="artifact_name" value="<?php echo h($row['title']); ?>">
              <input type="hidden" name="value" value="0">
              <input type="hidden" name="return_to" value="useby">
              <button type="submit" class="untrack-btn">Remove</button>
            </form>
          </td>
              <?php break;
              case 'overdue': ?>
          <td class="overdue" data-label="Overdue"<?php if ($row['overdue']) echo ' style="color: red;"'; ?>><?php echo $row['overdue'] ? 'Yes' : 'No'; ?></td>
              <?php break;
              case 'last_use': ?>
          <td class="mostRecentUse date hideOnPrint" data-label="Last interacted">
            <?php echo $row['last_use'] !== null ? h($row['last_use']) : '—'; ?>
          </td>
              <?php break;
              case 'acq': ?>
          <td class="acquisitionDate" data-label="Tracking start"><?php echo h($row['acq']); ?></td>
              <?php break;
              case 'interval': ?>
          <td class="interval" data-label="Interval"><?php echo h((string) $row['interval']); ?></td>
              <?php break;
            }
          } ?>
        </tr>
      <?php } ?>
    </tbody>
  </table>
  </div>

  <script src="<?php echo url_for('/shared/js/record-use-submit.js'); ?>"></script>
  <script src="<?php echo url_for('/shared/js/quick-record.js'); ?>"></script>
  <script src="<?php echo url_for('/artifacts/useby-table.js'); ?>"></script>

  <a href="https://www.flaticon.com/free-icons/copy" title="copy icons">Copy icons created by Anggara - Flaticon</a>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
