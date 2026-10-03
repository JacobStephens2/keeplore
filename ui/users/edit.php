<?php

  require_once('../../private/initialize.php');
  require_login();

  if(!isset($_GET['id'])) {
    redirect_to(url_for('/users/index.php'));
  }
  $id = (int) $_GET['id'];

  $user_id = $_SESSION['user_id'];
  $people = new People($db, (int) $user_id);
  $person = $people->find($id);
  if ($person === null) {
    $_SESSION['message'] = 'That user was not found.';
    redirect_to(url_for('/users/index.php'));
  }
  $form = [
    'FirstName' => $person['first_name'],
    'LastName' => $person['last_name'],
    'G' => $person['gender'],
    'birth_year' => (string) $person['birth_year'],
    'is_me' => $person['is_me'],
  ];

  if(is_post_request()) {

    // Merge another player into this one (issue #9, brief 3).
    if(isset($_POST['merge_loser_id'])) {
      $merge_errors = [];
      if(!isset($_POST['merge_confirm']) || $_POST['merge_confirm'] !== 'yes') {
        $merge_errors[] = "Confirm the merge before continuing.";
      } else {
        try {
          $people->merge($id, (int) $_POST['merge_loser_id']);
          $_SESSION['message'] = 'The players were merged successfully.';
          redirect_to(url_for('/users/show.php?id=' . h(u($id))));
        } catch (InvalidArgumentException $error) {
          $merge_errors[] = $error->getMessage();
        }
      }
      $errors = $merge_errors;
    } else {

    $form = [
      'FirstName' => $_POST['FirstName'] ?? '',
      'LastName' => $_POST['LastName'] ?? '',
      'G' => $_POST['G'] ?? '',
      'birth_year' => $_POST['birth_year'] ?? '',
      'is_me' => ($_POST['thisPlayerIsMe'] ?? '') === 'yes',
    ];

    try {
      $people->update($id, [
        'first_name' => $form['FirstName'],
        'last_name' => $form['LastName'],
        'gender' => $form['G'],
        'birth_year' => $form['birth_year'],
        'is_me' => $form['is_me'],
      ]);
      $_SESSION['message'] = 'The user was updated successfully.';
      redirect_to(url_for('/users/show.php?id=' . $id));
    } catch (InvalidArgumentException $error) {
      $errors[] = $error->getMessage();
    } catch (OutOfBoundsException) {
      $_SESSION['message'] = 'That user was not found.';
      redirect_to(url_for('/users/index.php'));
    }

  }

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
          value="<?php echo h($form['FirstName']); ?>"
        />
      </div>

      <div class="form-field">
        <label for="LastName">Last Name</label>
        <input
          type="text"
          id="LastName"
          name="LastName"
          value="<?php echo h($form['LastName']); ?>"
        />
      </div>

      <div class="form-field">
        <label for="Gender">Gender (M, F, or Other)</label>
        <input type="text" id="Gender" name="G" value="<?php echo h($form['G']); ?>" />
      </div>

      <div class="form-field">
        <label for="birth_year">Birth Year</label>
        <input type="number" id="birth_year" name="birth_year" value="<?php echo h($form['birth_year']); ?>" />
      </div>

      <div class="form-field form-field-check">
        <input type="hidden" name="thisPlayerIsMe" value="no">
        <input type="checkbox" name="thisPlayerIsMe" id="thisPlayerIsMe"
          value="yes"
          <?php echo $form['is_me'] ? 'checked' : ''; ?>
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
      <?php echo h($person['name']); ?>,
      and the selected player is deleted. This cannot be undone.
    </p>

    <form action="<?php echo url_for('/users/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>

      <label for="merge_loser_id">Player to merge in and delete</label>
      <select id="merge_loser_id" name="merge_loser_id">
        <?php foreach ($people->all() as $candidate) { ?>
          <?php if ($candidate['id'] === $person['id']) { continue; } ?>
          <option value="<?php echo h($candidate['id']); ?>"><?php echo h($candidate['name']); ?></option>
        <?php } ?>
      </select>

      <label for="merge_confirm">
        <input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes">
        <span id="merge_confirm_text">Yes, merge the selected player into
        <?php echo h($person['name']); ?>
        and delete it</span>
      </label>

      <input type="submit" value="Merge Players" />

    </form>

    <script>
      (function() {
        var loser = document.getElementById('merge_loser_id');
        var text = document.getElementById('merge_confirm_text');
        var survivor = <?php echo json_encode($person['name']); ?>;
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

  <?php include(SHARED_PATH . '/user_interactions.php'); ?>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
