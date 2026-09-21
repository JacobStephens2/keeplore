<?php
  $player_id = (int) $id;
  $user_id_int = (int) $user_id;
  $player_full_name = trim(($player['FirstName'] ?? '') . ' ' . ($player['LastName'] ?? ''));
  $participants = record_use_participants(
    $player_id,
    $player_full_name,
    (int) ($_SESSION['player_id'] ?? 0),
    (string) ($_SESSION['FullName'] ?? '')
  );
  $record_use_items = find_artifacts_by_user()->fetch_all(MYSQLI_ASSOC);
  usort($record_use_items, fn($a, $b) => strcasecmp($a['Title'], $b['Title']) ?: $a['id'] <=> $b['id']);
  $record_use_date = (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
  $record_use_setting = most_recent_use_setting($user_id_int);

  $stmt_interactions = mysqli_prepare($db, "SELECT
    uses.id AS use_id,
    DATE(uses.use_date) AS use_date,
    games.id AS artifactID,
    games.Title,
    games.type
    FROM uses_players
    JOIN uses ON uses.id = uses_players.use_id
    JOIN games ON games.id = uses.artifact_id
    WHERE uses_players.user_id = ?
      AND uses_players.player_id = ?
    ORDER BY uses.use_date DESC");
  mysqli_stmt_bind_param($stmt_interactions, "ii", $user_id_int, $player_id);
  mysqli_stmt_execute($stmt_interactions);
  $interactionsResult = mysqli_stmt_get_result($stmt_interactions);
?>
<section id="uses">
  <h2>
    <span id="use-count"><?php echo $interactionsResult->num_rows; ?></span>
    <?php echo h($player_full_name); ?>
    interactions are recorded
  </h2>

  <form id="record-use-form" class="record-use-form" method="post" action="<?php echo url_for('/uses/record-new.php'); ?>">
    <?php echo csrf_input(); ?>
    <h3>Record a use with <?php echo h($player_full_name); ?></h3>
    <p class="record-use-people">People: <?php
      echo h(implode(' and ', array_column($participants, 'name')));
    ?></p>
    <input type="hidden" name="return_to" value="user-edit">
    <input type="hidden" name="return_player_id" value="<?php echo h($id); ?>">
    <?php foreach ($participants as $index => $person) { ?>
      <input type="hidden" name="user[<?php echo (int) $index; ?>][id]" value="<?php echo h((string) $person['id']); ?>">
      <input type="hidden" name="user[<?php echo (int) $index; ?>][name]" value="<?php echo h($person['name']); ?>">
    <?php } ?>

    <div class="record-use-search">
      <label for="record-use-artifact-name">Item</label>
      <input
        type="search"
        id="record-use-artifact-name"
        name="artifact[name]"
        placeholder="Search items"
        autocomplete="off"
      >
      <input type="hidden" id="record-use-artifact-id" name="artifact[id]" value="">
      <div id="record-use-artifact-results" class="searchResults" style="display: none;">
        <ul id="record-use-artifact-results-list" class="searchResults"></ul>
      </div>
      <script type="application/json" id="record-use-item-options"><?php echo json_encode(
        array_map(static fn($item) => ['id' => (int) $item['id'], 'label' => $item['Title']], $record_use_items),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
      ); ?></script>
    </div>

    <label for="record-use-date">Date</label>
    <input type="date" name="useDate" id="record-use-date" value="<?php echo h($record_use_date); ?>" required>

    <label for="record-use-setting">Setting</label>
    <input type="text" name="Note" id="record-use-setting" value="<?php echo h($record_use_setting); ?>">

    <label for="record-use-notes">Notes</label>
    <textarea name="NotesTwo" id="record-use-notes" rows="3"></textarea>

    <div class="record-use-actions">
      <a class="modal-link" href="<?php echo url_for('/uses/record-new.php'); ?>" target="_blank">Open full form</a>
      <button type="submit" class="record-use-save">Record use</button>
    </div>
  </form>

  <div id="record-use-toast" class="toast" role="status" aria-live="polite"></div>

  <table id="useList" data-page-length='100'>
    <thead>
      <tr>
        <th>Interaction Date</th>
        <th>Item</th>
        <th>Type</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($interactionsResult as $row) { ?>
        <tr>
          <td>
            <a href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($row['use_id']))); ?>">
              <?php echo $row['use_date'] ? h($row['use_date']) : 'No date'; ?>
            </a>
          </td>
          <td>
            <a href="<?php echo url_for('/artifacts/edit.php?id=' . h(u($row['artifactID']))); ?>">
              <?php echo h($row['Title']); ?>
            </a>
          </td>
          <td><?php echo h($row['type']); ?></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>

  <script type="module">
    import SearchComponent from '/shared/js/search-component.js';

    const optionsEl = document.getElementById('record-use-item-options');
    const nameInput = document.getElementById('record-use-artifact-name');
    const idInput = document.getElementById('record-use-artifact-id');
    const resultsList = document.getElementById('record-use-artifact-results-list');
    if (optionsEl && nameInput && idInput) {
      const items = JSON.parse(optionsEl.textContent);

      function setItem(item) {
        idInput.value = String(item.id);
        nameInput.value = item.label;
      }

      nameInput.addEventListener('input', function () {
        idInput.value = '';
        const typed = nameInput.value.toLowerCase();
        const match = items.find((item) => item.label.toLowerCase() === typed);
        if (match) {
          idInput.value = String(match.id);
        }
      });

      nameInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || !resultsList) {
          return;
        }
        const first = resultsList.querySelector('li');
        if (first) {
          event.preventDefault();
          first.click();
        }
      });

      SearchComponent.create({
        inputSelector: '#record-use-artifact-name',
        resultsSelector: '#record-use-artifact-results-list',
        wrapperSelector: '#record-use-artifact-results',
        fetchResults: async (query) => {
          const needle = query.toLowerCase();
          return items.filter((item) => item.label.toLowerCase().includes(needle));
        },
        onSelect: setItem,
        maxResults: 10,
        debounceMs: 0,
      });
    }
  </script>
  <script src="<?php echo url_for('/shared/js/record-use-submit.js'); ?>"></script>
  <script>
    let table = new DataTable('#useList', {
      order: [[ 0, 'desc']]
    });

    (function () {
      var recordForm = document.getElementById('record-use-form');
      if (!recordForm || !window.RecordUseSubmit) return;

      var nameInput = document.getElementById('record-use-artifact-name');
      var idInput = document.getElementById('record-use-artifact-id');
      var notesInput = document.getElementById('record-use-notes');
      var countEl = document.getElementById('use-count');

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      RecordUseSubmit.bind(recordForm, {
        toastEl: document.getElementById('record-use-toast'),
        saveButton: recordForm.querySelector('.record-use-save'),
        saveLabel: 'Record use',
        artifactIdInput: idInput,
        onInvalid: function () {
          if (nameInput) nameInput.focus();
        },
        onSuccess: function (data) {
          var dateLabel = data.use_date ? escapeHtml(data.use_date) : 'No date';
          var dateCell = '<a href="<?php echo url_for('/uses/record-edit.php'); ?>?id='
            + encodeURIComponent(data.use_id) + '">' + dateLabel + '</a>';
          var itemCell = '<a href="<?php echo url_for('/artifacts/edit.php'); ?>?id='
            + encodeURIComponent(data.artifact_id) + '">'
            + escapeHtml(data.artifact_name || '') + '</a>';
          table.row.add([dateCell, itemCell, escapeHtml(data.artifact_type || '')]).draw(false);
          if (countEl) {
            countEl.textContent = String((parseInt(countEl.textContent, 10) || 0) + 1);
          }
          if (nameInput) nameInput.value = '';
          if (idInput) idInput.value = '';
          if (notesInput) notesInput.value = '';
        }
      });
    })();
  </script>
</section>
