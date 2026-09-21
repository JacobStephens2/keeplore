<?php

  require_once('../../private/initialize.php');
  require_login();

  if(!isset($_GET['id'])) {
    redirect_to(url_for('/users/index.php'));
  }
  $id = $_GET['id'];

  $user_id = $_SESSION['user_id'];

  if(is_post_request()) {

    // Merge another player into this one (issue #9, brief 3).
    if(isset($_POST['merge_loser_id'])) {
      $merge_errors = [];
      if(!isset($_POST['merge_confirm']) || $_POST['merge_confirm'] !== 'yes') {
        $merge_errors[] = "Confirm the merge before continuing.";
      } else {
        $merge_result = merge_players($id, $_POST['merge_loser_id'], $user_id);
        if($merge_result === true) {
          $_SESSION['message'] = 'The players were merged successfully.';
          redirect_to(url_for('/users/show.php?id=' . h(u($id))));
        } else {
          $merge_errors = $merge_result;
        }
      }
      $errors = $merge_errors;
      $player = find_player_by_id($id);
    } else {

    // Handle form values sent by new.php
    $player = [];
    $player['id'] = $id ?? '';
    $player['FirstName'] = $_POST['FirstName'] ?? '';
    $player['LastName'] = $_POST['LastName'] ?? '';
    $player['G'] = $_POST['G'] ?? '';
    $player['birth_year'] = $_POST['birth_year'] ?? '';
    $player['thisPlayerIsMe'] = $_POST['thisPlayerIsMe'] ?? '';
    $player['user_id'] = $user_id ?? '';

    $result = update_player($player);
    if($result === true) {
      $_SESSION['message'] = 'The user was updated successfully.';
      redirect_to(url_for('/users/show.php?id=' . $id));
    } else {
      $errors = $result;
    }

  }

  } else {

    $player = find_player_by_id($id);

  }

  $page_title = 'Edit User';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');
?>

<main>

  <div class="object edit">
    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form class="form-layout" action="<?php echo url_for('/users/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>

      <div class="form-field">
        <label for="FirstName">First Name</label>
        <input
          type="text"
          name="FirstName"
          id="FirstName"
          value="<?php echo h($player['FirstName']); ?>"
        />
      </div>

      <div class="form-field">
        <label for="LastName">Last Name</label>
        <input
          type="text"
          id="LastName"
          name="LastName"
          value="<?php echo h($player['LastName']); ?>"
        />
      </div>

      <div class="form-field">
        <label for="Gender">Gender (M, F, or Other)</label>
        <input type="text" id="Gender" name="G" value="<?php echo h($player['G']); ?>" />
      </div>

      <div class="form-field">
        <label for="birth_year">Birth Year</label>
        <input type="number" id="birth_year" name="birth_year" value="<?php echo h($player['birth_year']); ?>" />
      </div>

      <div class="form-field form-field-check">
        <input type="hidden" name="thisPlayerIsMe" value="no">
        <input type="checkbox" name="thisPlayerIsMe" id="thisPlayerIsMe"
          value="yes"
          <?php
            $stmt_rep = mysqli_prepare($db, "SELECT represents_user_id FROM players WHERE id = ?");
            mysqli_stmt_bind_param($stmt_rep, "i", $id);
            mysqli_stmt_execute($stmt_rep);
            $rep_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_rep));
            mysqli_stmt_close($stmt_rep);
            $userIDThisPlayerIDRepresents = $rep_result['represents_user_id'] ?? null;
            if ($userIDThisPlayerIDRepresents == $_SESSION['user_id']) {
              echo 'checked';
            }
          ?>
        >
        <label for="thisPlayerIsMe">This User Is Me</label>
      </div>

      <div class="form-field-span">
        <input type="submit" value="Save Edits" />
      </div>

    </form>

    <h2>Merge another player into this one</h2>
    <p>
      All of the selected player's recorded interactions move to
      <?php echo h($player['FirstName']) . ' ' . h($player['LastName']); ?>,
      and the selected player is deleted. This cannot be undone.
    </p>

    <form action="<?php echo url_for('/users/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>

      <label for="merge_loser_id">Player to merge in and delete</label>
      <select id="merge_loser_id" name="merge_loser_id">
        <?php
          $merge_candidates = find_players_by_user_id();
          while($candidate = mysqli_fetch_assoc($merge_candidates)) {
            if((int) $candidate['id'] === (int) $id) {
              continue;
            }
            echo "<option value=\"" . h($candidate['id']) . "\">"
              . h($candidate['FirstName'] . ' ' . $candidate['LastName'])
              . "</option>";
          }
          mysqli_free_result($merge_candidates);
        ?>
      </select>

      <label for="merge_confirm">
        <input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes">
        <span id="merge_confirm_text">Yes, merge the selected player into
        <?php echo h($player['FirstName']) . ' ' . h($player['LastName']); ?>
        and delete it</span>
      </label>

      <input type="submit" value="Merge Players" />

    </form>

    <script>
      (function() {
        var loser = document.getElementById('merge_loser_id');
        var text = document.getElementById('merge_confirm_text');
        var survivor = <?php echo json_encode($player['FirstName'] . ' ' . $player['LastName']); ?>;
        function updateMergeConfirm() {
          var name = loser.options[loser.selectedIndex].text;
          text.textContent = 'Yes, merge ' + name + ' into ' + survivor
            + ' and delete ' + name;
        }
        loser.addEventListener('change', updateMergeConfirm);
        updateMergeConfirm();
      })();
    </script>

  </div>

  <section id="uses">
    <?php
      $player_id = (int) $_REQUEST['id'];
      $user_id_int = (int) $user_id;
      $player_full_name = trim(($player['FirstName'] ?? '') . ' ' . ($player['LastName'] ?? ''));
      $session_player_id = (int) ($_SESSION['player_id'] ?? 0);
      $include_session_player = $session_player_id > 0 && $session_player_id !== $player_id;
      $record_use_items = find_artifacts_by_user()->fetch_all(MYSQLI_ASSOC);
      usort($record_use_items, fn($a, $b) => strcasecmp($a['Title'], $b['Title']) ?: $a['id'] <=> $b['id']);
      $record_use_date = (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
      $record_use_setting = singleValueQuery(
        "SELECT note FROM uses WHERE user_id = '" . $user_id_int . "' ORDER BY id DESC LIMIT 1"
      );

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
    <h2>
      <span id="use-count"><?php echo $interactionsResult->num_rows; ?></span>
      <?php echo h($player_full_name); ?>
      interactions are recorded
    </h2>

    <form id="record-use-form" class="record-use-form" method="post" action="<?php echo url_for('/uses/record-new.php'); ?>">
      <?php echo csrf_input(); ?>
      <h3>Record a use with <?php echo h($player_full_name); ?></h3>
      <p class="record-use-people">People: <?php
        echo h($player_full_name);
        if ($include_session_player) {
          echo ' and ' . h($_SESSION['FullName'] ?? 'Me');
        }
      ?></p>
      <input type="hidden" name="return_to" value="user-edit">
      <input type="hidden" name="return_player_id" value="<?php echo h($id); ?>">
      <input type="hidden" name="user[0][id]" value="<?php echo h($id); ?>">
      <input type="hidden" name="user[0][name]" value="<?php echo h($player_full_name); ?>">
      <?php if ($include_session_player) { ?>
        <input type="hidden" name="user[1][id]" value="<?php echo h((string) $session_player_id); ?>">
        <input type="hidden" name="user[1][name]" value="<?php echo h($_SESSION['FullName'] ?? ''); ?>">
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
      <input type="text" name="Note" id="record-use-setting" value="<?php echo h($record_use_setting ?? ''); ?>">

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
    <script>
      let table = new DataTable('#useList', {
        order: [[ 0, 'desc']]
      });

      (function () {
        var recordForm = document.getElementById('record-use-form');
        if (!recordForm) return;

        var toastEl = document.getElementById('record-use-toast');
        var toastTimer = null;
        var saveBtn = recordForm.querySelector('.record-use-save');
        var nameInput = document.getElementById('record-use-artifact-name');
        var idInput = document.getElementById('record-use-artifact-id');
        var notesInput = document.getElementById('record-use-notes');
        var countEl = document.getElementById('use-count');

        function showToast(message, kind) {
          if (!toastEl) { alert(message); return; }
          toastEl.textContent = message;
          toastEl.classList.remove('toast-success', 'toast-error', 'is-visible');
          toastEl.classList.add(kind === 'error' ? 'toast-error' : 'toast-success');
          void toastEl.offsetWidth;
          toastEl.classList.add('is-visible');
          if (toastTimer) clearTimeout(toastTimer);
          toastTimer = setTimeout(function () {
            toastEl.classList.remove('is-visible');
          }, 3500);
        }

        function escapeHtml(value) {
          return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        }

        function addUseRow(data) {
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
        }

        recordForm.addEventListener('submit', function (event) {
          event.preventDefault();
          if (!idInput || !idInput.value) {
            showToast('Please choose an item.', 'error');
            if (nameInput) nameInput.focus();
            return;
          }
          if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }
          fetch(recordForm.action, {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(recordForm),
          })
            .then(function (response) {
              return response.json().then(function (data) {
                return { ok: response.ok, data: data };
              });
            })
            .then(function (result) {
              if (result.ok && result.data && result.data.ok) {
                addUseRow(result.data);
                if (nameInput) nameInput.value = '';
                if (idInput) idInput.value = '';
                if (notesInput) notesInput.value = '';
                showToast(result.data.message || 'Interaction recorded.', 'success');
              } else {
                var msg = (result.data && result.data.message) || 'Could not record the use.';
                showToast(msg, 'error');
              }
              if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Record use'; }
            })
            .catch(function (error) {
              showToast('Network error: ' + error.message, 'error');
              if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Record use'; }
            });
        });
      })();
    </script>
  </section>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
