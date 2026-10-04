<?php
require_once('../../private/initialize.php');
require_login();

require_once(PRIVATE_PATH . '/item_form.php');

$default_interval = (new Preferences($db, (int) $_SESSION['user_id']))->get()['default_use_interval'];

$artifact = item_form_create_values();

if(is_post_request()) {

  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  $input = item_form_input($_POST);

  try {
    $new_id = (new Items($db, (int) $_SESSION['user_id']))->create($input);

    if ($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode([
        'ok' => true,
        'id' => $new_id,
        'Title' => $input['Title'] ?? '',
      ]);
      exit;
    }

    $_SESSION['message'] = 'The item was created successfully.';
    redirect_to(url_for('/artifacts/show.php?id=' . $new_id));
  } catch (ItemInvalid $invalid) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(422);
      echo json_encode(['ok' => false, 'message' => $invalid->getMessage()]);
      exit;
    }
    $errors = $invalid->errors;
    $artifact = array_replace($artifact, $input);
  }

}

$page_title = 'Create Item';include(SHARED_PATH . '/header.php');

?>

<main>

  <div class="object new">
    <h1>Create Item</h1>

    <section class="create-item-lookup" aria-labelledby="create-item-lookup-heading">
      <h2 id="create-item-lookup-heading">Already on your account?</h2>
      <p>Search your items to see whether this one is already on your account, and whether it is kept. You can mark it kept or not kept here instead of creating a duplicate.</p>
      <label class="list-search-wrap">
        <span class="sr-only">Search your items</span>
        <input type="search" id="create-item-lookup-search" class="list-search" placeholder="Search by name" autocomplete="off" spellcheck="false">
      </label>
      <div class="table-scroll">
        <table class="list" id="create-item-lookup">
          <thead>
            <tr>
              <th data-sort="is_kept">Kept</th>
              <th data-sort="title" id="create-item-lookup-name-header">Name</th>
            </tr>
          </thead>
          <tbody id="create-item-lookup-body">
            <tr class="list-status">
              <td colspan="2">Loading items…</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div id="create-item-lookup-pager" class="list-pager"></div>
    </section>
    <div id="create-item-lookup-toast" class="toast" role="status" aria-live="polite"></div>

    <?php echo display_errors($errors); ?>

    <form class="form-layout" data-shortcut="save" action="<?php echo url_for('/artifacts/new'); ?>" method="POST">
      <?php echo csrf_input(); ?>

      <div class="form-field-span create-item-submit">
        <button type="submit">Create Item <kbd>s</kbd></button>
      </div>

      <?php echo item_form_html('create', $artifact, $default_interval); ?>

      <div id="operations" class="form-field-span create-item-submit">
        <button type="submit">Create Item <kbd>s</kbd></button>
      </div>
    </form>

  </div>

</main>

<script src="<?php echo url_for('/artifacts/bgg-default-type.js'); ?>?v=2"></script>
<script src="<?php echo url_for('/artifacts/new-bgg.js'); ?>?v=12"></script>
<script src="<?php echo url_for('/shared/js/form-save-shortcut.js'); ?>?v=1"></script>
<script src="<?php echo url_for('/shared/js/list-table.js'); ?>?v=3"></script>
<script type="application/json" id="create-item-lookup-config"><?php
  echo json_encode([
    'dataUrl' => url_for('/artifacts/items-data.php'),
    'itemUrlPrefix' => url_for('/artifacts/edit.php?id='),
    'keptToggleUrl' => url_for('/artifacts/set-tracked.php'),
    'csrfToken' => generate_csrf_token(),
    'return_to' => 'new',
    'pageLength' => 10,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
?></script>
<script src="<?php echo url_for('/shared/js/create-item-lookup.js'); ?>?v=1"></script>

<?php include(SHARED_PATH . '/footer.php'); ?>
