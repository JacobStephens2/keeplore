<?php
require_once('../../private/initialize.php');

require_login();

$name = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';

if(is_post_request()) {
  try {
    (new Types($db, (int) $_SESSION['user_id']))->create($name);
    $_SESSION['message'] = 'The type was created successfully.';
    redirect_to(url_for('/types/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  }
}

?>

<?php $page_title = 'Add Type'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

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
        <input type="submit" value="Add" />
      </div>
    </form>

    <a href="/types">
      <p>List of types</p>
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
