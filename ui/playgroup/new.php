<?php
require_once('../../private/initialize.php');
require_login();
$people = (new People($db, (int) $_SESSION['user_id']))->all();
$playerCount = max(1, min(9, (int) ($_POST['playerCount'] ?? $_GET['playerCount'] ?? 1)));
$chosen = [];
for ($i = 1; $i <= $playerCount; $i++) {
  $chosen[$i] = is_scalar($_POST['Player' . $i] ?? null) ? (string) $_POST['Player' . $i] : '';
}

if(is_post_request()) {
  try {
    (new Playgroup($db, (int) $_SESSION['user_id']))->add($chosen);
    $_SESSION['message'] = 'The playgroup was successfully expanded.';
    redirect_to(url_for('/playgroup/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  }
}

$page_title = 'Add User to Group';
include(SHARED_PATH . '/header.php');
?>

<main>

  <h2>User Count</h2>
    <form action="<?php echo url_for('/playgroup/new.php'); ?>" method="get">
      <select name="playerCount">
        <?php
          $i = 1;
          while ($i < 10) {
            echo "<option value=\"" . $i . "\"";
            if($i == $playerCount) {
              echo " selected";
            }
            echo ">" . $i . "</option>";
            $i++;
          }
        ?>
      </select>
      <input type="submit" value="Select User Count" />
    </form>


  <div class="object new">
    <h1>Add to group</h1>

    <?php echo display_errors($errors); ?>

    <form action="<?php echo url_for('/playgroup/new.php'); ?>" method="post">
      <?php echo csrf_input(); ?>
      <dl>
        <?php foreach ($chosen as $i => $choice) { ?>
        <dd>
          <select name="Player<?php echo $i; ?>">
            <option value="">Choose a person</option>
          <?php
            foreach ($people as $person) {
              echo "<option value=\"" . h($person['id']) . "\"";
              if ($choice === (string) $person['id']) {
                echo " selected";
              }
              echo ">" . h($person['name']) . "</option>";
            }
          ?>
          </select>
        </dd>
        <?php } ?>
      </dl>
      <input type="hidden" name="playerCount" value="<?php echo $playerCount; ?>">
      <div id="operations">
        <input type="submit" value="Add to playgroup" />
      </div>
    </form>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
