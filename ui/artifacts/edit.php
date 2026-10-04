<?php
  require_once('../../private/initialize.php');
  require_once(PRIVATE_PATH . '/item_facts.php');
  require_once(PRIVATE_PATH . '/item_form.php');
  require_login();
  if(!isset($_GET['id'])) {
    redirect_to(url_for('/artifacts/index.php'));
  }
  $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($id === false) {
    error_404();
  }

  $items = new Items($db, (int) $_SESSION['user_id']);
  $artifact = $items->find($id);
  if (!$artifact) {
    error_404();
  }

  $default_interval = (new Preferences($db, (int) $_SESSION['user_id']))->get()['default_use_interval'];

  if(is_post_request()) {
    $input = item_form_input($_POST);
    try {
      $items->update($id, $input);
      $_SESSION['message'] = 'The item was updated successfully.';
      redirect_to(url_for('/artifacts/edit.php?id=' . $id));
    } catch (ItemInvalid $invalid) {
      $errors = $invalid->errors;
    } catch (OutOfBoundsException $not_found) {
      error_404();
    }
  }

  // After a rejected save the form shows what was typed, to fix in one pass.
  $artifact = array_replace($artifact, $input ?? []);

  $page_title = h($artifact['Title']); 
  include(SHARED_PATH . '/header.php'); 
?>

<link rel="stylesheet" href="<?php echo url_for('/proposals/proposals.css'); ?>">
<main>

  <div id="editArtifact" class="object edit">
    <h1>Edit <?php echo h($artifact['Title']); ?></h1>
    <?php $play_facts = item_type_is_game($artifact['type'] ?? '') ? item_play_facts($artifact) : ''; ?>
    <?php if ($play_facts !== '') { ?>
      <p class="item-play-facts"><?php echo h($play_facts); ?></p>
    <?php } ?>

    <?php
      $picture = normalize_item_image_url($artifact['image_url'] ?? '');
      if ($picture !== '') {
    ?>
    <p class="item-picture-wrap">
      <img class="item-picture" src="<?php echo h($picture); ?>" alt="<?php echo h($artifact['Title']); ?>" referrerpolicy="no-referrer">
    </p>
    <?php } ?>

    <?php echo item_bgg_link_html($artifact['bgg_url'] ?? ''); ?>
    <?php $bgg_ratings = new BggRatings($db, (int) $_SESSION['user_id']); ?>
    <?php $item_bgg_ratings = $bgg_ratings->forItems([$id])[$id] ?? []; ?>
    <?php echo item_bgg_ratings_html($item_bgg_ratings); ?>
    <?php foreach ($bgg_ratings->reviewers() as $bgg_reviewer) {
      $bgg_rating = $item_bgg_ratings[$bgg_reviewer] ?? ['rating' => null, 'comment' => null]; ?>
      <details class="bgg-rating-edit">
        <summary>Edit <?php echo h($bgg_reviewer); ?> rating and comment</summary>
        <form method="post" action="<?php echo url_for('/artifacts/bgg-rating-save.php'); ?>">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="artifact_id" value="<?php echo h((string) $id); ?>">
          <input type="hidden" name="bgg_username" value="<?php echo h($bgg_reviewer); ?>">
          <label>
            Rating (1 to 10)
            <input type="number" name="rating" min="1" max="10" step="0.01" value="<?php echo $bgg_rating['rating'] === null ? '' : h(bgg_score_text($bgg_rating['rating'])); ?>">
          </label>
          <label>
            Comment
            <textarea name="comment" rows="6"><?php echo h((string) $bgg_rating['comment']); ?></textarea>
          </label>
          <p class="bgg-rating-edit-note">Clear both to remove it. The import leaves your entry alone; Request <?php echo h($bgg_reviewer); ?> data replaces it only when BoardGameGeek has one.</p>
          <button type="submit">Save <?php echo h($bgg_reviewer); ?> rating</button>
        </form>
      </details>
      <?php if (bgg_thing_id_from_url($artifact['bgg_url'] ?? '') > 0) { ?>
        <form class="bgg-rating-request" method="post" action="<?php echo url_for('/artifacts/bgg-rating-request.php'); ?>">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="artifact_id" value="<?php echo h((string) $id); ?>">
          <input type="hidden" name="bgg_username" value="<?php echo h($bgg_reviewer); ?>">
          <button type="submit">Request <?php echo h($bgg_reviewer); ?> data</button>
        </form>
      <?php } ?>
    <?php } ?>

    <?php echo display_errors($errors); ?>

    <div class="edit-actions">
      <a class="back-link"
        href="<?php echo url_for('/uses/record-new.php?artifact_id=' . h(u($id))); ?>"
        >
        Record Use
      </a>

      <a class="back-link" href="<?php echo url_for('/proposals/edit.php?item_id=' . h(u($id))); ?>">
        Record proposal outcome
      </a>

      <button id="editFormDisplayButton" type="button">
        Toggle Edit Form Display
      </button>
    </div>

    <form id="editForm" class="form-layout" data-shortcut="save"
      action="<?php echo url_for('/artifacts/edit?id=' . h(u($id))); ?>"
      method="post"
      >
      <?php echo csrf_input(); ?>

      <div class="form-field-span">
        <button type="submit">Save Edits <kbd>s</kbd></button>
      </div>

      <?php echo item_form_html('edit', $artifact, $default_interval); ?>

      <div class="form-field-span">
        <button type="submit">Save Edits <kbd>s</kbd></button>
      </div>
    </form>

  </div>

  <section id="interactionsList">
    <?php
      $item_uses = (new Uses($db, (int) $_SESSION['user_id']))->all(['item_id' => $id]);
    ?>
    <h2>
      You have recorded
      <?php echo count($item_uses); ?>
      interactions with
      <?php echo h($artifact['Title']); ?>
    </h2>
    <table>
      <tr>
        <th>Interaction Date</th>
        <th>People</th>
      </tr>
      <?php foreach ($item_uses as $use) { ?>
        <tr>
          <td>
            <a href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($use['id']))); ?>">
              <?php echo h($use['use_date']); ?>
            </a>
          </td>
          <td><?php echo h(implode(', ', array_column($use['people'], 'name'))); ?></td>
        </tr>
      <?php } ?>
    </table>
  </section>

  <?php include(SHARED_PATH . '/proposal_history.php'); ?>

  <?php
    require_once PRIVATE_PATH . '/item_merge.php';
    $merge_candidates = item_merge_candidates($items->list(), $artifact);
  ?>
  <?php if ($merge_candidates !== []) { ?>
  <section class="item-merge">
    <h2>Merge another item into this one</h2>
    <p>
      The chosen item's uses, proposals, tags and BoardGameGeek ratings move to
      <?php echo h($artifact['Title']); ?>, which keeps its own details; the
      chosen item's details (players, age, BGG link, notes) are discarded
      with it. This cannot be undone.
    </p>
    <form method="post" action="<?php echo url_for('/artifacts/merge.php'); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="artifact_id" value="<?php echo h((string) $id); ?>">
      <label for="merge_loser_id">Item to merge in and delete</label>
      <select id="merge_loser_id" name="merge_loser_id" required>
        <option value="">Choose an item</option>
        <?php foreach ($merge_candidates as $candidate) { ?>
          <option value="<?php echo h((string) $candidate['id']); ?>">
            <?php echo h($candidate['Title'] . ' (#' . $candidate['id'] . ')' . ($candidate['same_name'] ? ' - same name' : '')); ?>
          </option>
        <?php } ?>
      </select>
      <label for="merge_confirm">
        <input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes">
        Yes, merge the chosen item into <?php echo h($artifact['Title']); ?> and delete it
      </label>
      <button type="submit">Merge Items</button>
    </form>
  </section>
  <?php } ?>

  <p id="deleteArtifact">
    <a class="action" href="<?php echo url_for('/artifacts/delete.php?id=' . h(u($_REQUEST['id']))); ?>">
      Delete 
      <?php echo h($artifact['Title']); ?>
    </a>
  </p>

</main>

<script>
  let editForm = document.querySelector('#editForm');

  function toggleEditFormDisplay() {
    editForm.hidden = !editForm.hidden;
  }

  let editFormDisplayButton = document.querySelector('#editFormDisplayButton');

  editFormDisplayButton.addEventListener('click', toggleEditFormDisplay);
</script>
<script src="<?php echo url_for('/shared/js/form-save-shortcut.js'); ?>?v=1"></script>
<script src="<?php echo url_for('/artifacts/new-bgg.js'); ?>?v=12"></script>

<?php include(SHARED_PATH . '/footer.php'); ?>
