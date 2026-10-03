<?php

  global $db;
  global $type_id;

  $match_found = false;
  foreach (array_column((new Types($db, (int) $_SESSION['user_id']))->all(), 'id', 'name') as $type => $id) {
    ?>
    <option 
      value="<?php echo $id; ?>" 
      <?php
        if ($id == $type_id) { 
          $match_found = true;
          echo ' selected ';
        } 
        if ($id == DEFAULT_TYPE && $match_found === false) {
          echo ' selected ';
        }
      ?>
      >
      <?php echo $type; ?>
    </option>

    <?php
  }

?>