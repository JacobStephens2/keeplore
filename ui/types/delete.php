<?php
require_once('../../private/initialize.php');
$page_title = 'Delete Type';

require_login();

$types = new Types($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);
$type = $types->find($id);
if ($type === null) {
  $_SESSION['message'] = 'This type has already been deleted.';
  redirect_to(url_for('/types/index.php'));
}
$move_items_to = is_string($_POST['move_items_to'] ?? null) ? $_POST['move_items_to'] : '';

if(is_post_request()) {
  try {
    $types->delete($id, $move_items_to === '' ? null : (int) $move_items_to);
    $_SESSION['message'] = 'The type ' . $type['name'] . ' was deleted.';
    redirect_to(url_for('/types/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  } catch (OutOfBoundsException) {
    $_SESSION['message'] = 'This type has already been deleted.';
    redirect_to(url_for('/types/index.php'));
  }
}

include(SHARED_PATH . '/header.php'); 

?>

<main>

  <div class="object new">
    <h1><?php echo $page_title . ' ' . h($type['name']); ?></h1>

    <?php echo display_errors($errors); ?>

    <p>Are you sure you wish to delete type <?php echo h($type['name']); ?>?</p>
    <p>
      You keep <?php echo $type['kept_count']; ?> items with this type.
      You have <?php echo $type['not_kept_count']; ?> items with this type that you do not keep.
    </p>
    <form method="post">
      <?php echo csrf_input(); ?>
      <label for="move_items_to">
        Move its items to
      </label>
      <select name="move_items_to" id="move_items_to">
        <option value=""<?php if ($move_items_to === '') { echo ' selected'; } ?>>Leave them without a type</option>
        <?php foreach ($types->all() as $other) { ?>
          <?php if ($other['id'] !== $id) { ?>
          <option value="<?php echo $other['id']; ?>"<?php if ($move_items_to === (string) $other['id']) { echo ' selected'; } ?>>
            <?php echo h($other['name']); ?>
          </option>
          <?php } ?>
        <?php } ?>
      </select>

      <div>
        <input type="submit" value="Yes" />
      </div>
    </form>

    <a href="/types">
      <p>List of types</p>
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
