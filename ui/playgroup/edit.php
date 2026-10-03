<?php
require_once('../../private/initialize.php');
require_login();
if(!isset($_GET['ID'])) {
  redirect_to(url_for('/playgroup/index.php'));
}
$ID = $_GET['ID'];
if(is_post_request()) {
  // Handle form values sent by new.php

  $playgroupplayer = [];
  $playgroupplayer['ID'] = $ID ?? '';
  $playgroupplayer['FullName'] = $_POST['FullName'] ?? '';

  $result = update_playgroup_player($playgroupplayer);
  if($result === true) {
    $_SESSION['message'] = 'The playgroup player was updated successfully.';
    redirect_to(url_for('/playgroup/index.php?'));
  } else {
    $errors = $result;
    //var_dump($errors);
  }

} else {

  $playgroupplayer = find_playgroup_player_by_id($ID);

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
            <option value='Invalid'>Choose a player</option>
          <?php
            foreach ((new People($db, (int) $_SESSION['user_id']))->all() as $person) {
              echo "<option value=\"" . h($person['id']) . "\"";
              if($playgroupplayer['FullName'] == $person['id']) {
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
