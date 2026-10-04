<?php
require_once('../../private/initialize.php');
require_login();

$aversions = new Aversions($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);
$aversion = $aversions->find($id);
if ($aversion === null) {
  error_404();
}

if(is_post_request()) {
  $aversion['item_id'] = (int) ($_POST['Title'] ?? 0);
  $aversion['person_id'] = (int) ($_POST['Player'] ?? 0);
  $aversion['date'] = (string) ($_POST['AversionDate'] ?? '');
  try {
    $aversions->update($id, $aversion['item_id'], $aversion['person_id'], $aversion['date']);
    $_SESSION['message'] = 'The aversion was updated successfully.';
    redirect_to(url_for('/aversions/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  } catch (OutOfBoundsException) {
    error_404();
  }
}

$page_title = 'Edit Aversion';
include(SHARED_PATH . '/header.php');

?>

<main>

  <div class="object edit">
    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form class="form-layout" action="<?php echo url_for('/aversions/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>
      <div class="form-field">
        <label for="Title">Item</label>
        <select id="Title" name="Title">
        <?php
          foreach ((new Items($db, (int) $_SESSION['user_id']))->list() as $item) {
            echo "<option value=\"" . h($item['id']) . "\"";
            if($aversion['item_id'] === (int) $item['id']) {
              echo " selected";
            }
            echo ">" . h($item['Title']) . "</option>";
          }
        ?>
        </select>
      </div>
      <div class="form-field">
        <label for="User">User</label>
        <select id="User" name="Player">
          <option value="">Choose a person</option>
          <?php
            foreach ((new People($db, (int) $_SESSION['user_id']))->all() as $person) {
              echo "<option value=\"" . h($person['id']) . "\"";
              if($aversion['person_id'] === $person['id']) {
                echo " selected";
              }
              echo ">" . h($person['name']) . "</option>";
            }
          ?>
        </select>
      </div>

      <div class="form-field">
        <label for="AversionDate">Aversion Date</label>
        <input type="date" name="AversionDate" id="AversionDate" value="<?php echo h($aversion['date']); ?>" />
      </div>

      <div class="form-field-span">
        <input type="submit" value="Save Aversion" />
      </div>
    </form>

    <a 
      class="action" 
      href="<?php echo url_for('/aversions/delete.php?id=' . h(u($id))); ?>"
    >
      <button>
        Delete Aversion
      </button>
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
