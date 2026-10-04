<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/kept_status.php';
require_once __DIR__ . '/item_types.php';
require_once __DIR__ . '/record_use.php';
require_once __DIR__ . '/classes/Items.php';

/**
 * The Item form: the Item fields Create Item and Edit Item share, rendered
 * from one field list and read back into the Items module's input.
 *
 * item_form_html() takes the form's mode, 'create' or 'edit', and the
 * Item's values: the stored Item (or item_form_create_values()) with the
 * typed values laid over it after a rejected save. Tags may be a list
 * (stored) or the typed string. Every value is escaped. An Interaction
 * frequency of null shows the owner's default use interval.
 *
 * Create only: Name autofocuses; the BGG lookup panel follows Name, with
 * the owner's Type for BoardGameGeek items, a hidden picture and link, and
 * the picture preview; Notes then Tags close the form.
 * Edit only: To get rid of, Secondary collection, the visible BoardGameGeek
 * link with a lookup panel that keeps the name, and the BGG vote basis
 * under each BGG-filled field.
 *
 * Every checkbox posts an explicit 0 through a hidden input, so
 * item_form_input() needs no mode. Rendering reads the session owner's
 * types (and on Create their Type for BoardGameGeek items) through the
 * global $db, as the shared type search does.
 */

/** Each posted field and the Items module's input key it fills. */
const ITEM_FORM_FIELDS = [
  'Title' => 'Title', 'type' => 'type_id', 'tags' => 'tags', 'Acq' => 'Acq',
  'interaction_frequency_days' => 'interaction_frequency_days',
  'SS' => 'SS', 'age' => 'Age', 'MnP' => 'MnP', 'MxP' => 'MxP', 'MnT' => 'MnT', 'MxT' => 'MxT',
  'Yr' => 'Yr', 'Notes' => 'Notes', 'image_url' => 'image_url',
  'bgg_url' => 'bgg_url', 'bgg_player_votes' => 'bgg_player_votes',
  'bgg_age_basis' => 'bgg_age_basis', 'BGG_Rat' => 'BGG_Rat',
  'is_kept' => 'is_kept', 'to_get_rid_of' => 'to_get_rid_of',
  'is_in_secondary_collection' => 'is_in_secondary_collection',
];

/** A posted Item form as the Items module's input. Fields it doesn't carry stay missing. */
function item_form_input(array $form): array {
  $input = [];
  foreach (ITEM_FORM_FIELDS as $field => $key) {
    if (array_key_exists($field, $form)) {
      $input[$key] = $form[$field];
    }
  }
  return $input;
}

/** Create Item's starting values: the Items module's defaults, today's date and blanks. */
function item_form_create_values(): array {
  return [
    'Title' => '', 'type_id' => null, 'tags' => '', 'Acq' => record_use_today(),
    'interaction_frequency_days' => null, 'Yr' => '', 'Notes' => '', 'image_url' => '',
    'bgg_url' => '', 'bgg_player_votes' => '', 'bgg_age_basis' => '', 'BGG_Rat' => '',
  ] + Items::DEFAULTS;
}

/** The Item fields, escaped, for $mode 'create' or 'edit'. */
function item_form_html(string $mode, array $item, $default_interval): string {
  if (!in_array($mode, ['create', 'edit'], true)) {
    throw new InvalidArgumentException("Unknown Item form mode: {$mode}");
  }
  $create = $mode === 'create';
  $value = fn($key) => h((string) ($item[$key] ?? ''));
  $checkbox = function ($name, $label, $checked, $span = false) {
    return '<div class="form-field form-field-check' . ($span ? ' form-field-span' : '') . '">'
      . '<input type="hidden" name="' . $name . '" value="0" />'
      . '<input type="checkbox" name="' . $name . '" id="' . $name . '" value="1"' . ($checked ? ' checked' : '') . ' />'
      . '<label for="' . $name . '">' . $label . ' (Checked means yes)</label></div>';
  };
  $tags = $item['tags'] ?? '';
  $tags = is_array($tags) ? implode(', ', $tags) : (string) $tags;
  $tags_field = '<div class="form-field' . ($create ? ' form-field-span' : '') . '">
        <label for="tags">Tags (comma-separated)</label>
        <input type="text" name="tags" id="tags" value="' . h($tags) . '" placeholder="portable, beach-safe, two-player, party" />
      </div>';
  $basis = fn($group, $id) => $create ? '' : item_bgg_field_basis_html($item, $group, $id);
  $rating = h((string) (bgg_overall_rating_text($item['BGG_Rat'] ?? $item['bgg_rat'] ?? null) ?? ''));
  $interval = $item['interaction_frequency_days'] ?? null;

  ob_start();
?>
      <div class="form-field form-field-span">
        <label for="Title">Name</label>
        <input type="text" name="Title" id="Title"<?php if ($create) { ?> autofocus<?php } ?> value="<?php echo $value('Title'); ?>" />
      </div>
<?php if ($create) { ?>

      <div class="form-field form-field-span">
        <?php
          global $db;
          $bgg_default_type = user_bgg_default_type($db, (int) $_SESSION['user_id']);
          $bgg_form_default_type_id = DEFAULT_TYPE;
          include SHARED_PATH . '/bgg_lookup_panel.php';
          $preview_url = normalize_item_image_url($item['image_url'] ?? '');
        ?>
        <input type="hidden" name="image_url" id="image_url" value="<?php echo h($preview_url); ?>">
        <input type="hidden" name="bgg_url" id="bgg_url" value="<?php echo h(normalize_item_bgg_url($item['bgg_url'] ?? '')); ?>">
        <input type="hidden" name="bgg_player_votes" id="bgg_player_votes" value="<?php echo $value('bgg_player_votes'); ?>">
        <input type="hidden" name="bgg_age_basis" id="bgg_age_basis" value="<?php echo $value('bgg_age_basis'); ?>">
        <input type="hidden" name="BGG_Rat" id="BGG_Rat" value="<?php echo $rating; ?>">
        <img id="itemPicturePreview" class="item-picture-preview"
          alt="<?php echo $preview_url !== '' ? $value('Title') . ' cover' : ''; ?>"
          <?php if ($preview_url !== '') { ?>src="<?php echo h($preview_url); ?>"<?php } else { ?>hidden<?php } ?>
          referrerpolicy="no-referrer">
      </div>
<?php } ?>

      <?php echo $checkbox('is_kept', 'Kept?', artifact_is_kept($item)); ?>

<?php if (!$create) { ?>
      <?php echo $checkbox('to_get_rid_of', 'To Get Rid Of?', (string) ($item['to_get_rid_of'] ?? '') === '1'); ?>

<?php } ?>
      <div class="form-field">
        <?php
          $type_id = $item['type_id'] ?? '';
          if ($create && ($type_id === '' || $type_id === null)) {
            $type_id = DEFAULT_TYPE;
          }
          require SHARED_PATH . '/artifact_type_search.php';
        ?>
      </div>

<?php if (!$create) { echo $tags_field; ?>

<?php } ?>
      <div class="form-field">
        <label for="Acq">Tracking Start Date</label>
        <input type="date" name="Acq" id="Acq" value="<?php echo $value('Acq'); ?>" />
      </div>

      <div class="form-field">
        <label for="interaction_frequency_days">Interaction Frequency (Days)</label>
        <input type="number" step="0.1" name="interaction_frequency_days" id="interaction_frequency_days"
          onwheel="this.blur()"
          value="<?php echo h((string) ($interval ?? $default_interval)); ?>"
        >
      </div>

      <div class="form-field">
        <label for="SS">Sweet Spot(s)</label>
        <input type="text" name="SS" id="SS" aria-describedby="ss-hint<?php if (!$create) { ?> SS-bgg-basis<?php } ?>" value="<?php echo $value('SS'); ?>">
        <p id="ss-hint" class="form-field-hint">Ideal player counts, comma-separated. Example: 2, 3, 4</p>
        <?php echo $basis('sweet_spot', 'SS'); ?>
      </div>

      <div class="form-field">
        <label for="age">Minimum Age</label>
        <input type="number" name="age" id="age"<?php if (!$create) { ?> aria-describedby="age-bgg-basis"<?php } ?> value="<?php echo $value('Age'); ?>">
        <?php echo $basis('age', 'age'); ?>
      </div>

      <div class="form-field">
        <label for="MnP">Minimum User Count</label>
        <input type="number" name="MnP" id="MnP"<?php if (!$create) { ?> aria-describedby="MnP-bgg-basis"<?php } ?> value="<?php echo $value('MnP'); ?>">
        <?php echo $basis('players', 'MnP'); ?>
      </div>

      <div class="form-field">
        <label for="MxP">Maximum User Count</label>
        <input type="number" name="MxP" id="MxP"<?php if (!$create) { ?> aria-describedby="MxP-bgg-basis"<?php } ?> value="<?php echo $value('MxP'); ?>">
        <?php echo $basis('players', 'MxP'); ?>
      </div>

      <div class="form-field">
        <label for="MnT">Minimum Time</label>
        <input type="number" name="MnT" id="MnT" value="<?php echo $value('MnT'); ?>">
      </div>

      <div class="form-field">
        <label for="MxT">Maximum Time</label>
        <input type="number" name="MxT" id="MxT" value="<?php echo $value('MxT'); ?>">
      </div>

      <div class="form-field">
        <label for="Yr">Year</label>
        <input type="number" name="Yr" id="Yr" min="1" max="9999" step="1" value="<?php echo $value('Yr'); ?>">
      </div>
<?php if (!$create) { ?>

      <div class="form-field form-field-span">
        <?php $bgg_keep_title = true; include SHARED_PATH . '/bgg_lookup_panel.php'; ?>
        <input type="hidden" name="bgg_player_votes" id="bgg_player_votes" value="<?php echo $value('bgg_player_votes'); ?>">
        <input type="hidden" name="bgg_age_basis" id="bgg_age_basis" value="<?php echo $value('bgg_age_basis'); ?>">
        <input type="hidden" name="BGG_Rat" id="BGG_Rat" value="<?php echo $rating; ?>">
        <label for="bgg_url">BoardGameGeek Link</label>
        <input type="url" name="bgg_url" id="bgg_url" maxlength="1024"
          placeholder="https://boardgamegeek.com/boardgame/..."
          value="<?php echo h(normalize_item_bgg_url($item['bgg_url'] ?? '')); ?>"
        >
      </div>

      <?php echo $checkbox('is_in_secondary_collection', 'Kept in Secondary Collection?', artifact_is_in_secondary_collection($item), true); ?>
<?php } ?>

      <div class="form-field form-field-span">
        <label for="Notes">Notes</label>
        <textarea name="Notes" id="Notes" cols="30" rows="10"><?php echo $value('Notes'); ?></textarea>
      </div>
<?php if ($create) { ?>

      <?php echo $tags_field; ?>
<?php } ?>
<?php
  return ob_get_clean();
}
