<?php
  // Request BGG Data lookup shared by Create Item and Edit Item. Set
  // $bgg_keep_title = true before including to leave the Name field alone
  // when a match is used. Needs ui/artifacts/new-bgg.js on the page.
  $bgg_keep_title = $bgg_keep_title ?? false;
?>
        <div class="bgg-lookup">
          <button type="button" id="requestBggData"<?php if ($bgg_keep_title) { ?> data-keep-title<?php } ?>>Request BGG Data</button>
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
