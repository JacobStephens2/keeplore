<?php
  require_once SHARED_PATH . '/artifact_type_array.php';
  global $typesArray;

  $current_type_id = isset($type_id) ? (string) $type_id : '';
  $current_type_name = '';
  foreach ($typesArray as $type_name => $tid) {
    if ((string) $tid === $current_type_id) {
      $current_type_name = $type_name;
      break;
    }
  }
?>
<label for="type_search">Type</label>
<input type="text" id="type_search" list="type_list"
  value="<?php echo h($current_type_name); ?>"
  placeholder="Search types"
  autocomplete="off"
/>
<input type="hidden" name="type" id="type" value="<?php echo h($current_type_id); ?>" />
<datalist id="type_list">
  <?php foreach ($typesArray as $type_name => $tid) { ?>
    <option value="<?php echo h($type_name); ?>" data-id="<?php echo h($tid); ?>"></option>
  <?php } ?>
</datalist>
<script>
  document.getElementById('type_search').addEventListener('input', function() {
    var options = document.querySelectorAll('#type_list option');
    var hidden = document.getElementById('type');
    var val = this.value;
    hidden.value = '';
    for (var i = 0; i < options.length; i++) {
      if (options[i].value === val) {
        hidden.value = options[i].dataset.id;
        break;
      }
    }
  });
</script>
