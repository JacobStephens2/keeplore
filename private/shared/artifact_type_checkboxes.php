<?php

  require_once dirname(__DIR__) . '/item_types.php';
  global $db;
  global $type;
  $type_ids_by_name = array_column((new Types($db, (int) $_SESSION['user_id']))->all(), 'id', 'name');
  // Pages that tidy their filter panel (Items, #15) set
  // $type_filter_shortcuts = 'trimmed' before including this partial to
  // keep only Select All / Deselect All plus one game-type shortcut. Every
  // other consumer keeps the full shortcut set.
  global $type_filter_shortcuts;
  $trimmed_shortcuts = isset($type_filter_shortcuts) && $type_filter_shortcuts === 'trimmed';

?>

<style>

  #typeCheckboxes label {
    margin-right: 1.2rem;
    display: inline;
  }

  #typeCheckboxes input {
    display: inline;
    height: 1.5rem;
    width: 1.5rem;
  }

  #typeCheckboxes span {
    white-space: nowrap;
  }

  #type #typeCheckboxes input[type="checkbox"] {
      margin-right: 0.3rem;
      margin-bottom: 1rem;
  }

  #selectButtons button {
    font-size: 1rem;
    margin: 0.3rem 0.2rem;
  }

</style>

<div id="selectButtons">
  <button id="selectAll">Select All</button>
  <button id="deselectAll">Deselect All</button>
  <button id="selectGames" data-type-ids="<?php echo h(json_encode(item_game_type_ids($type_ids_by_name))); ?>">Select Games</button>
  <?php if (!$trimmed_shortcuts) { ?>
  <button id="selectAnalogGames">Select Analog Games</button>
  <button id="selectOnlineGames">Select Online Games</button>
  <button id="selectOutdoorGames">Select Outdoor Games</button>
  <?php } ?>
</div>

<span id="typeCheckboxes" style="display: flex; flex-wrap: wrap">
  <?php
    foreach ($type_ids_by_name as $type_name => $id) {
      ?>
      <span>
        <input
          type="checkbox"
          id="<?php 
            if ($type_name == '') {
              echo 'no-type';
            } else {
              echo str_replace(' ', '-', $type_name);
            }
          ?>" 
          value="<?php echo $id; ?>" 
          name="type[<?php echo $id; ?>]"
          <?php 
            if (gettype($type) === 'array') {
              if(in_array($id, $type)) { 
                echo ' checked '; 
              }
            }
          ?>
        >
        <label>
          <?php 
            if ($type_name === '') {
              echo 'no type';
            } else {
              echo str_replace('-', ' ', $type_name); 
            }
          ?>
        </label>
      </span>
      <?php
    }
  ?>
</span>

<script>
  document.querySelector('#deselectAll').addEventListener('click', function(event) {
    event.preventDefault();
    document.querySelectorAll('#typeCheckboxes input').forEach(element => element.checked = false);
  })

  document.querySelector('#selectAll').addEventListener('click', function(event) {
    event.preventDefault();
    document.querySelectorAll('#typeCheckboxes input').forEach(element => element.checked = true);
  })
  
  document.querySelector('#selectGames').addEventListener('click', function(event) {
    event.preventDefault();
    // The game types come from item_game_type_ids(), so a new kind of game
    // (card game) is included without editing this list.
    var gameTypeIds = JSON.parse(this.getAttribute('data-type-ids') || '[]');
    document.querySelectorAll('#typeCheckboxes input').forEach(function (element) {
      element.checked = gameTypeIds.indexOf(element.value) !== -1;
    });
  })
  
  <?php if (!$trimmed_shortcuts) { ?>
  document.querySelector('#selectAnalogGames').addEventListener('click', function(event) {
    event.preventDefault();
    document.querySelectorAll('#typeCheckboxes input').forEach(element => element.checked = false);
    document.querySelector('#typeCheckboxes #gambling-game').checked = true;
    document.querySelector('#typeCheckboxes #game').checked = true;
    document.querySelector('#typeCheckboxes #gambling-game').checked = true;
    document.querySelector('#typeCheckboxes #role-playing-game').checked = true;
    document.querySelector('#typeCheckboxes #sport').checked = true;
    document.querySelector('#typeCheckboxes #table-game').checked = true;
  })

  document.querySelector('#selectOnlineGames').addEventListener('click', function(event) {
    event.preventDefault();
    document.querySelectorAll('#typeCheckboxes input').forEach(element => element.checked = false);
    document.querySelector('#typeCheckboxes #individual-display').checked = true;
    document.querySelector('#typeCheckboxes #mobile-game').checked = true;
    document.querySelector('#typeCheckboxes #vr-game').checked = true;
  })

  document.querySelector('#selectOutdoorGames').addEventListener('click', function(event) {
    event.preventDefault();
    document.querySelectorAll('#typeCheckboxes input').forEach(element => element.checked = false);
    document.querySelector('#typeCheckboxes #gambling-game').checked = true;
    document.querySelector('#typeCheckboxes #game').checked = true;
    document.querySelector('#typeCheckboxes #mobile-game').checked = true;
    document.querySelector('#typeCheckboxes #sport').checked = true;
    document.querySelector('#typeCheckboxes #toy').checked = true;
    document.querySelector('#typeCheckboxes #equipment').checked = true;
  })
  <?php } ?>
</script>

