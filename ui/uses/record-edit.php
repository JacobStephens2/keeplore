<?php
require_once('../../private/initialize.php');
require_login();

$uses = new Uses($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);
$use = $uses->find($id);
if ($use === null) {
  $_SESSION['message'] = 'That use was not found.';
  redirect_to(url_for('/uses/interactions.php'));
}

if(is_post_request()) {
  // A person row whose name was cleared is no longer on the use.
  $people = array_filter($_POST['user'] ?? [], fn ($person) => ($person['name'] ?? '') !== '');
  try {
    $uses->update($id, [
      'item_id' => $_POST['artifact_id'] ?? '',
      'use_date' => $_POST['use_date'] ?? '',
      'setting' => $_POST['note'] ?? '',
      'notes' => $_POST['notesTwo'] ?? '',
      'player_ids' => array_column($people, 'id'),
    ]);
    $_SESSION['message'] = 'The use was updated successfully.';
    $use = $uses->find($id);
  } catch (InvalidArgumentException $error) {
    $errors = [$error->getMessage()];
  } catch (OutOfBoundsException $error) {
    $_SESSION['message'] = 'That use was not found.';
    redirect_to(url_for('/uses/interactions.php'));
  }
}

$page_title = 'Edit Interaction';
include(SHARED_PATH . '/header.php'); 

?>

<script type="module" src="modules/getUsers.js"></script>

<main>

  <div class="object edit">

    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form
      action="<?php echo url_for('/uses/record-edit.php?id=' . h(u($use['id']))); ?>"
      method="post"
      >
      <?php echo csrf_input(); ?>

      <label for="UseDate">Interaction Date</dt>
      <input 
        type="date" 
        id="UseDate" 
        name="use_date" 
        value="<?php echo h($use['use_date']); ?>" 
      />

      <label for="Title">Item</label>      <select id="Title" name="artifact_id">
        <?php
          foreach ((new Items($db, (int) $_SESSION['user_id']))->list() as $item) {
            echo "<option value=\"" . h($item['id']) . "\"";
            if($use['item_id'] == $item['id']) {
              echo " selected";
            }
            echo ">" . h($item['Title']) . "</option>";
          }
        ?>
      </select>

      <label for="users">People List</label>
      <section id="users">
        <?php
        $i = 0;
        foreach ($use['people'] as $user) {
          ?>
          <div class="person-row" id="personRow<?php echo $i; ?>">
            <input 
              type="search" 
              class="user" 
              id="user<?php echo $i; ?>name" 
              name="user[<?php echo $i; ?>][name]" 
              value="<?php echo h($user['name']); ?>"
              data-userid="<?php echo $_SESSION['user_id']; ?>"
              autocomplete="off"
            >
            <input 
              type="hidden" 
              id="user<?php echo $i; ?>id" 
              name="user[<?php echo $i; ?>][id]" 
              value="<?php echo (int) $user['id']; ?>"
            >
            <div class="userResults" id="userResultsDiv<?php echo $i; ?>" style="display: none;">
              <ul class="userResults" id="userResults<?php echo $i; ?>"></ul>
            </div>
          </div>
          <?php
          $i++;
        }
        ?>

      </section>

      <button 
        id="addUser"
        class="user"
        type="button"
        style="display: block;"
        >
        +
      </button>

      
      

      <label for="Note">Setting</label>
      <input type="text"
        name="note" 
        id="Note" 
        value="<?php echo h($use['setting']); ?>" 
      >

      <label for="notesTwo">Notes</label>
      <textarea 
        name="notesTwo" 
        id="notesTwo" 
        cols="30" 
        rows="10"
        ><?php echo h($use['notes']); ?></textarea>

      

      <input type="submit" value="Save use" />

    </form>

  </div>

  <a 
    class="action" 
    href="<?php echo url_for('/uses/record-delete.php?id=' . h(u($use['id']))); ?>"
  >
    <button>
      Delete Interaction
    </button>
  </a>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
