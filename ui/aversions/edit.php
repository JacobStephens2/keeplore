<?php
require_once('../../private/initialize.php');
require_login();

if(!isset($_GET['id'])) {
  redirect_to(url_for('/aversions/index.php'));
}
$id = $_GET['id'];

if(is_post_request()) {
  // handle post requests sent by this page
  $response = [];
  $response['id'] = $id ?? '';
  $response['Title'] = $_POST['Title'] ?? '';
  $response['PlayDate'] = $_POST['PlayDate'] ?? '';
  $response['Player'] = $_POST['Player'] ?? '';

  $result = update_response($response);
  if($result === true) {
    $_SESSION['message'] = 'The object was updated successfully.';
    redirect_to(url_for('/aversions/index.php'));
  } else {
    $errors = $result;
  }
} else {
  $response = find_response_by_id($id);
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
            if($response["responsetitle"] == $item['id']) {
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
          <option value='Invalid'>Choose a User</option>
          <?php
            foreach ((new People($db, (int) $_SESSION['user_id']))->all() as $person) {
              echo "<option value=\"" . h($person['id']) . "\"";
              if($response["Player"] == $person['id']) {
                echo " selected";
              }
              echo ">" . h($person['name']) . "</option>";
            }
          ?>
        </select>
      </div>

      <div class="form-field">
        <label for="AversionDate">Aversion Date</label>
        <input type="date" name="AversionDate" id="AversionDate" value="<?php echo h($response['AversionDate']); ?>" />
      </div>

      <input type="hidden" name="id" value="<?php echo h($response['id']); ?>" />

      <div class="form-field-span">
        <input type="submit" value="Save Aversion" />
      </div>
    </form>

    <a 
      class="action" 
      href="<?php echo url_for('/aversions/delete.php?id=' . h(u($response['id']))); ?>"
    >
      <button>
        Delete Aversion
      </button>
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
