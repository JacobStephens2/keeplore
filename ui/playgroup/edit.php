<?php
require_once('../../private/initialize.php');
require_login();
$playgroup = new Playgroup($db, (int) $_SESSION['user_id']);
$ID = (int) ($_GET['ID'] ?? 0);
$member = $playgroup->member($ID);
if ($member === null) {
  error_404();
}
$person_id = $member['person_id'];
if(is_post_request()) {
  $person_id = (int) ($_POST['FullName'] ?? 0);
  try {
    $playgroup->replace($ID, $person_id);
    $_SESSION['message'] = 'The playgroup player was updated successfully.';
    redirect_to(url_for('/playgroup/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  } catch (OutOfBoundsException) {
    error_404();
  }
}

?>

<?php $page_title = 'Edit Group User'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <div class="object edit">
    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form action="<?php echo url_for('/playgroup/edit.php?ID=' . h(u($ID))); ?>" method="post">
      <?php echo csrf_input(); ?>
    <dl>
        <dt>User</dt>
        <dd>
          <select name="FullName">
            <option value="">Choose a person</option>
          <?php
            foreach ((new People($db, (int) $_SESSION['user_id']))->all() as $person) {
              echo "<option value=\"" . h($person['id']) . "\"";
              if($person_id === $person['id']) {
                echo " selected";
              }
              echo ">" . h($person['name']) . "</option>";
            }
          ?>
          </select>
        </dd>
      </dl>
      <div ID="operations">
        <input type="submit" value="Save group user edit" />
      </div>
    </form>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
