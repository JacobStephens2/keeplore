<?php
/**
 * Quick record popup: record a use without leaving the page.
 *
 * Expects $db and the owner's $user_id in scope. The owner's person, from
 * $_SESSION['player_id'] and $_SESSION['FullName'], is the fixed first person. The Setting opens as the owner's last Setting,
 * otherwise their default Setting from Preferences.
 *
 * ui/shared/js/quick-record.js drives it; the page passes its toast and
 * what to do with its own row once a use is recorded.
 */
$quick_record_user_id = (int) $user_id;
$quick_record_setting = (new Uses($db, $quick_record_user_id))->lastSetting()
  ?? (new Preferences($db, $quick_record_user_id))->get()['default_setting'];
$quick_record_player_id = (string) ($_SESSION['player_id'] ?? '');
$quick_record_owner_name = (string) ($_SESSION['FullName'] ?? '');
?>
<div id="record-modal" class="modal" hidden aria-hidden="true"
  data-people-search-url="<?php echo h('https://' . API_ORIGIN . '/users.php'); ?>"
  data-new-person-url="<?php echo url_for('/users/new.php'); ?>"
  data-user-id="<?php echo h((string) $quick_record_user_id); ?>">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="record-modal-title">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2 id="record-modal-title" class="modal-title">Record interaction</h2>
    <p id="record-modal-artifact" class="modal-subtitle"></p>
    <form id="record-modal-form" method="post" action="<?php echo url_for('/uses/record-new.php'); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="artifact[id]" id="record-modal-artifact-id">
      <input type="hidden" name="artifact[name]" id="record-modal-artifact-name">

      <label>People</label>
      <div id="record-modal-users">
        <div class="modal-user-chip">
          <span class="modal-user-name"><?php echo h($quick_record_owner_name !== '' ? $quick_record_owner_name : 'Me'); ?></span>
          <input type="hidden" name="user[0][id]" id="record-modal-owner-id" value="<?php echo h($quick_record_player_id); ?>">
          <input type="hidden" name="user[0][name]" value="<?php echo h($quick_record_owner_name); ?>">
        </div>
      </div>

      <div id="record-modal-add-user">
        <div class="modal-user-search-wrap" id="record-modal-user-search-wrap">
          <input type="search" id="record-modal-user-search" placeholder="Add another person…" autocomplete="off">
          <ul id="record-modal-user-results" class="modal-user-results" hidden></ul>
        </div>
        <button type="button" id="record-modal-new-user-toggle" class="new-interactor-toggle">+ New person</button>
      </div>

      <div id="record-modal-new-user-form" class="new-interactor-form" style="display: none;">
        <input type="text" id="record-modal-new-first" placeholder="First name" autocomplete="off">
        <input type="text" id="record-modal-new-last" placeholder="Last name" autocomplete="off">
        <button type="button" id="record-modal-new-create" class="new-interactor-create">Create &amp; add</button>
        <button type="button" id="record-modal-new-cancel" class="new-interactor-cancel">Cancel</button>
        <span id="record-modal-new-msg" class="new-interactor-msg" role="status" aria-live="polite"></span>
      </div>

      <label for="record-modal-date">Date</label>
      <input type="date" name="useDate" id="record-modal-date" required>

      <label for="record-modal-setting">Setting</label>
      <input type="text" name="Note" id="record-modal-setting" value="<?php echo h($quick_record_setting ?? ''); ?>">

      <label for="record-modal-notes">Notes</label>
      <textarea name="NotesTwo" id="record-modal-notes" rows="3"></textarea>

      <div class="modal-actions">
        <a class="modal-link" id="record-modal-fullform-link" href="<?php echo url_for('/uses/record-new.php'); ?>" target="_blank">Open full form</a>
        <button type="button" class="modal-cancel" data-modal-close>Cancel</button>
        <button type="submit" class="modal-save">Save</button>
      </div>
    </form>
  </div>
</div>
