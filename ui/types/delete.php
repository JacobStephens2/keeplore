<?php
require_once('../../private/initialize.php');
global $db;
$page_title = 'Delete Type';

require_login();

$id = db_escape($db, $_GET['id']);
$type = singleValueQuery("SELECT ObjectType FROM types WHERE id = '$id'");
$user_id = $_SESSION['user_id'];

if ($type === 'No results') {
  $message = "This type has already been deleted.";
} else {
  if(is_post_request()) {
  
  
    $query = 
      "DELETE FROM types
      WHERE user_id = '$user_id'
      AND id = '$id'
      LIMIT 1
    ";
    $result = query($query);
  
    if ($result === true) {
      $message = "Deletion of type $type succeeded. ";
    } else {
      $message = "Deletion of type $type failed. ";
    }

    $new_type = db_escape($db, $_POST['new_type']);
    $query = 
      "UPDATE games
      SET type = '$new_type'
      WHERE user_id = '$user_id'
      AND type = '$type'
    ";

    if ($result === true) {
      $message .= "Update of of types that had type $type to new type $new_type succeeded. ";
    } else {
      $message .= "Update of of types that had $type to new type $new_type failed. ";
    }
  
  }
}

include(SHARED_PATH . '/header.php'); 

?>

<main>

  <div class="object new">
    <h1>
      <?php 
        echo $page_title;
        if ($type !== 'No results') {
          echo " " . $type;
        }
      ?>
    </h1>

    <?php 
      echo display_errors($errors); 
      if (isset($message)) {
        echo "<p>$message</p>";
      }
    ?>

    
    <?php
      if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        ?>
        <p>Are you sure you wish to delete type <?php echo $type; ?>?</p>
        <p>
          <?php
            $count_kept_with_this_type = singleValueQuery(
              "SELECT COUNT(id) FROM games
              WHERE type = '$type'
              AND id = '$user_id'
              AND is_kept = '1'
            ");
            echo "You keep $count_kept_with_this_type items with this type. ";
            $count_not_kept_with_this_type = singleValueQuery(
              "SELECT COUNT(id) FROM games
              WHERE type = '$type'
              AND id = '$user_id'
              AND is_kept = '0'
            ");
            echo "You have created $count_not_kept_with_this_type items with this type that you do not keep. ";
          ?>
        </p>
        <form method="post">
          <?php echo csrf_input(); ?>
          <label for="new_type">
            Recategorize Existing <?php echo $type; ?> items with this type
          </label>
          <select name="new_type" id="new_type">
            <?php
              include(SHARED_PATH . '/artifact_type_array.php'); 
              global $typesArray;
              foreach ($typesArray as $type) {
                ?>
                <option value="<?php echo $type; ?>">
                  <?php echo $type; ?>
                </option>
                <?php
              }
            ?>
          </select>

          <div>
            <input type="submit" value="Yes" />
          </div>
        </form>
        <?php
      } else {
        ?>
        <a href="/types">
          <p>List of types</p>
        </a>
        <?php
      }
    ?>

    

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
