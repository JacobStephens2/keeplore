<?php
require_once('../../private/initialize.php');
$page_title = 'Edit Type';

require_login();

$types = new Types($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);
$type = $types->find($id);
if ($type === null) {
  $_SESSION['message'] = 'This type has already been deleted.';
  redirect_to(url_for('/types/index.php'));
}
$name = $type['name'];

if(is_post_request()) {
  $name = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
  try {
    $types->rename($id, $name);
    $_SESSION['message'] = 'The type was updated successfully.';
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
    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form method="post">
      <?php echo csrf_input(); ?>
      <dl>
        <dt>Type</dt>
        <dd><input type="text" name="type" value="<?php echo h($name); ?>" /></dd>
      </dl>
      <div>
        <input type="submit" value="Edit" />
      </div>
    </form>

    <a href="/types/delete?id=<?php echo $id; ?>">
      <p>Delete</p>
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
