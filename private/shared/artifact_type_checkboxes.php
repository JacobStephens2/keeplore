<?php

  /**
   * The type checkboxes and their shortcut buttons. The including page sets
   * $type_filter to ['types' => [name => id], 'selected' => [id strings]],
   * the Type filter module's answer (or the Items page's own selection in
   * that shape), so what is ticked matches what the page queried.
   *
   * Pages that tidy their filter panel (Items, #15) set
   * $type_filter_shortcuts = 'trimmed' to keep only Select All / Deselect
   * All plus the game-type shortcut. Every other page keeps the full set.
   */
  require_once dirname(__DIR__) . '/type_filter.php';
  $type_ids_by_name = $type_filter['types'];
  $selected_type_ids = $type_filter['selected'];
  $trimmed_shortcuts = isset($type_filter_shortcuts) && $type_filter_shortcuts === 'trimmed';
  $shortcut_type_ids = type_filter_shortcut_ids($type_ids_by_name);
  $shortcut_buttons = ['selectGames' => ['games', 'Select Games']];
  if (!$trimmed_shortcuts) {
    $shortcut_buttons += [
      'selectAnalogGames' => ['analog', 'Select Analog Games'],
      'selectOnlineGames' => ['online', 'Select Online Games'],
      'selectOutdoorGames' => ['outdoor', 'Select Outdoor Games'],
    ];
  }

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
  <?php foreach ($shortcut_buttons as $button_id => [$shortcut, $button_label]) { ?>
  <button id="<?php echo $button_id; ?>" data-type-ids="<?php echo h(json_encode($shortcut_type_ids[$shortcut])); ?>"><?php echo $button_label; ?></button>
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
              echo h(type_filter_slug($type_name));
            }
          ?>" 
          value="<?php echo $id; ?>" 
          name="type[<?php echo $id; ?>]"
          <?php if (in_array((string) $id, $selected_type_ids, true)) { echo ' checked '; } ?>
        >
        <label>
          <?php 
            if ($type_name === '') {
              echo 'no type';
            } else {
              echo h(str_replace('-', ' ', $type_name));
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
  
  // Each shortcut ticks exactly the Type ids it carries, worked out on the
  // server from the owner's Types, so a Type the owner lacks is skipped.
  document.querySelectorAll('#selectButtons button[data-type-ids]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var typeIds = JSON.parse(this.getAttribute('data-type-ids') || '[]');
      document.querySelectorAll('#typeCheckboxes input').forEach(function (element) {
        element.checked = typeIds.indexOf(element.value) !== -1;
      });
    });
  })
</script>

