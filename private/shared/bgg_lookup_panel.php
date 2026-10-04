<?php
  // Request BGG Data lookup shared by Create Item and Edit Item. Set
  // $bgg_keep_title = true before including to leave the Name field alone
  // when a match is used. Set $bgg_default_type (from Types::bggDefault())
  // with $bgg_form_default_type_id (the form's own default Type) to give a
  // used match that Type while Type is still that default or blank. Needs
  // ui/artifacts/new-bgg.js on the page.
  $bgg_keep_title = $bgg_keep_title ?? false;
  $bgg_default_type = $bgg_default_type ?? null;
  $bgg_form_default_type_id = $bgg_form_default_type_id ?? '';
?>
        <div class="bgg-lookup">
          <button type="button" id="requestBggData"<?php if ($bgg_keep_title) { ?> data-keep-title<?php } ?><?php if ($bgg_default_type !== null) { ?> data-default-type-id="<?php echo (int) $bgg_default_type['id']; ?>" data-default-type-name="<?php echo h($bgg_default_type['name']); ?>" data-form-default-type-id="<?php echo h((string) $bgg_form_default_type_id); ?>"<?php } ?>>Request BGG Data</button>
          <p class="bgg-lookup-status" id="bggLookupStatus" hidden></p>
          <div class="bgg-confirm" id="bggConfirm" hidden>
            <img id="bggMatchImage" class="bgg-match-image" alt="" hidden referrerpolicy="no-referrer">
            <p>
              <strong id="bggMatchName"></strong>
              <span id="bggMatchYearWrap">(<span id="bggMatchYear"></span>)</span>
              <span id="bggMatchSource" class="bgg-source" hidden></span>
            </p>
            <p>
              <a id="bggMatchLink" href="#" target="_blank" rel="noopener">View on BoardGameGeek</a>
            </p>
            <div class="bgg-confirm-actions">
              <button type="button" id="bggUseMatch">Use this game</button>
            </div>
            <ul class="bgg-other-matches" id="bggOtherMatches" hidden></ul>
          </div>
        </div>
<?php unset($bgg_keep_title); ?>
