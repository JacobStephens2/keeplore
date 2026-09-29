<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/bgg_poll_index.php');

$filters = bgg_poll_search_filters($_GET);
$searched = $filters['best'] !== null || $filters['age'] !== null;
$games = $searched ? bgg_poll_search($db, $filters, BGG_POLL_SEARCH_LIMIT + 1) : [];
$more = count($games) > BGG_POLL_SEARCH_LIMIT;
$games = array_slice($games, 0, BGG_POLL_SEARCH_LIMIT);
$kept = $searched ? bgg_poll_kept_things($db, (int) $_SESSION['user_id']) : [];
$reviewers = $searched ? item_bgg_reviewers($db, (int) $_SESSION['user_id']) : [];
$reviews = $reviewers !== [] ? bgg_reviews_by_thing($db, (int) $_SESSION['user_id']) : [];
$summary = bgg_poll_index_summary($db);

$page_title = 'Search BGG';
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/bgg-search/bgg-search.css?v=2'); ?>">
<main class="bgg-search-page">
  <header class="page-header">
    <p class="section-label">BoardGameGeek</p>
    <h1>Search BGG by community votes</h1>
    <p class="page-lede">Find games the BoardGameGeek community voted Best at a player count, rated for a young player's age, or both.</p>
  </header>

  <form class="filter-panel bgg-search-form" method="get" action="<?php echo url_for('/bgg-search/index.php'); ?>">
    <label>Best with
      <select name="best">
        <option value="">Any count</option>
        <?php for ($n = 1; $n <= 12; $n++) { ?>
          <option value="<?php echo $n; ?>"<?php if ($filters['best'] === $n) { echo ' selected'; } ?>><?php echo $n; ?> <?php echo $n === 1 ? 'player' : 'players'; ?></option>
        <?php } ?>
      </select>
    </label>
    <label class="bgg-search-check">
      <input type="checkbox" name="skip_open" value="1"<?php if ($filters['skip_open']) { echo ' checked'; } ?>>
      Leave out open-ended Best (N+)
    </label>
    <label>Good for ages
      <select name="age">
        <option value="">Any age</option>
        <?php for ($age = 2; $age <= 18; $age++) { ?>
          <option value="<?php echo $age; ?>"<?php if ($filters['age'] === $age) { echo ' selected'; } ?>><?php echo $age; ?>+</option>
        <?php } ?>
      </select>
    </label>
    <label>Fewest player-poll votes
      <input type="number" name="min_votes" min="0" step="1" inputmode="numeric" value="<?php echo $filters['min_votes'] > 0 ? $filters['min_votes'] : ''; ?>" placeholder="0">
    </label>
    <button type="submit">Search</button>
    <p class="menu-support bgg-search-help">Good for ages 6+ finds games the community rates for 6-year-olds or younger (6+, 5+, 4+...). A Best vote such as 4+ counts for every larger group unless you leave open-ended Best out; 9+ still counts at 9.</p>
  </form>

  <?php if ($summary['polled'] === 0) { ?>
    <div class="empty-state"><p>The BGG index is empty. Run <code>bin/refresh-bgg-poll-index</code> on the server to fill it.</p></div>
  <?php } elseif (!$searched) { ?>
    <p>Choose a Best player count, an age, or both.</p>
  <?php } elseif ($games === []) { ?>
    <div class="empty-state"><p>No indexed game matches.</p></div>
  <?php } else { ?>
    <p><?php echo $more ? 'The ' . BGG_POLL_SEARCH_LIMIT . ' best-ranked of more than ' . BGG_POLL_SEARCH_LIMIT . ' games.' : count($games) . ' ' . (count($games) === 1 ? 'game.' : 'games.'); ?></p>
    <p class="list-sort-hint">Click a column heading to sort by it; Shift-click adds a tie-breaker.</p>
    <p class="list-sort-summary" id="bgg-search-sort-summary" aria-live="polite"></p>
    <div class="surface-panel">
    <div class="table-scroll">
    <table class="list bgg-search-results" id="bgg-search-results">
      <thead>
        <tr>
          <th data-sort="name">Game</th>
          <th data-sort="best">Best with</th>
          <th data-sort="votes">Player-poll votes</th>
          <th data-sort="age">Community age</th>
          <th data-sort="rank">BGG rank</th>
          <th data-sort="average">Average</th>
          <th data-sort="kept">Kept</th>
          <?php foreach ($reviewers as $reviewer) { ?>
            <th data-sort="bgg_rating:<?php echo h($reviewer); ?>"><?php echo h($reviewer); ?></th>
          <?php } ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($games as $game) { ?>
          <tr
            data-name="<?php echo h($game['name']); ?>"
            data-best="<?php echo $game['best_players'] === '' ? '' : (int) $game['best_players']; ?>"
            data-votes="<?php echo h((string) $game['player_votes']); ?>"
            data-age="<?php echo h((string) $game['community_age']); ?>"
            data-rank="<?php echo h((string) $game['bgg_rank']); ?>"
            data-average="<?php echo h((string) $game['average']); ?>"
            data-kept="<?php echo isset($kept[$game['thing_id']]) ? '1' : '0'; ?>"
            data-ratings="<?php echo h(json_encode(array_map(fn ($review) => $review['rating'], $reviews[$game['thing_id']] ?? []), JSON_FORCE_OBJECT)); ?>"
          >
            <td class="name">
              <a href="<?php echo h($game['url']); ?>" target="_blank" rel="noopener"><?php echo h($game['name']); ?></a>
              <?php if ($game['year_published'] !== null) { ?><span class="menu-support">(<?php echo h($game['year_published']); ?>)</span><?php } ?>
              <?php if ($game['subdomains'] !== '') { ?><small class="menu-support"><?php echo h($game['subdomains']); ?></small><?php } ?>
            </td>
            <td><?php echo h($game['best_players']); ?></td>
            <td><?php echo number_format($game['player_votes']); ?></td>
            <td><?php echo $game['community_age'] === null ? '' : h($game['community_age']) . '+'; ?></td>
            <td><?php echo $game['bgg_rank'] === null ? '' : number_format($game['bgg_rank']); ?></td>
            <td><?php echo $game['average'] === null ? '' : h(number_format($game['average'], 2)); ?></td>
            <td>
              <?php if (isset($kept[$game['thing_id']])) { ?>
                <a href="<?php echo url_for('/artifacts/edit.php?id=' . $kept[$game['thing_id']]); ?>">Kept</a>
              <?php } ?>
            </td>
            <?php foreach ($reviewers as $reviewer) {
              $review = $reviews[$game['thing_id']][$reviewer] ?? null; ?>
              <td class="bgg-search-review">
                <?php if ($review !== null) { ?>
                  <?php if ($review['rating'] !== null) { ?>
                    <a href="<?php echo url_for('/artifacts/edit.php?id=' . $review['artifact_id']); ?>"><?php echo h(bgg_score_text($review['rating'])); ?></a>
                  <?php } ?>
                  <?php if ((string) $review['comment'] !== '') { ?>
                    <details><summary>Comment</summary><p><?php echo h($review['comment']); ?></p></details>
                  <?php } ?>
                <?php } ?>
              </td>
            <?php } ?>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    </div>
    </div>
    <script src="<?php echo url_for('/shared/js/list-table.js'); ?>?v=3"></script>
    <script src="<?php echo url_for('/bgg-search/bgg-search.js'); ?>?v=2"></script>
  <?php } ?>

  <p class="menu-support bgg-search-source">
    Covers the top-ranked games of each BGG subdomain: <?php echo number_format($summary['polled']); ?> games with polls<?php if ($summary['last_fetched'] !== null) { ?>, last fetched <?php echo h(substr($summary['last_fetched'], 0, 10)); ?><?php } ?>.
    Player-poll votes count everyone who voted on the game's player-count poll.<?php if ($reviewers !== []) { ?> The <?php echo h(implode(' and ', $reviewers)); ?> <?php echo count($reviewers) === 1 ? 'column shows' : 'columns show'; ?> the ratings and comments on your Keeplore items that link to the game.<?php } ?> BGG's data does not give a vote count for the age poll.
    Data from <a href="https://boardgamegeek.com" target="_blank" rel="noopener">BoardGameGeek</a>.
  </p>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
