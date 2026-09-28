<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/EventPlans.php');
require_once(PRIVATE_PATH . '/event_plan.php');

$plans = new EventPlans($db, (int) $_SESSION['user_id']);
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$event = $id ? $plans->find($id) : null;
if ($event === null) {
    error_404();
}

// The grouping chosen last for this event stays until changed.
$dimensions = event_plan_dimensions();
$grouping = event_plan_grouping($_GET, $_SESSION['event_grouping'][$id] ?? []);
$_SESSION['event_grouping'][$id] = $grouping;
['by' => $by, 'then' => $then, 'tags' => $tags] = $grouping;
$by_tag = $by === 'tag' || $then === 'tag';

$items = $event['items'];
$players = $event['players'];
$groups = event_plan_groups($items, $by, $then, event_plan_chosen_tags($tags), array_column($players, 'age'));
$packed = count(array_filter(array_column($items, 'is_packed')));
$not_kept = count(array_filter($items, fn($item) => !$item['is_kept']));
$to_add = $plans->itemsToAdd($id);
$players_to_add = $plans->playersToAdd($id);
$player_ages_label = event_player_ages($players);
$settings = array_values(array_unique(array_filter(array_map('trim', array_column($items, 'setting')))));
sort($settings, SORT_NATURAL | SORT_FLAG_CASE);
$dates = event_dates_label($event['starts_on'], $event['ends_on']);

$page_title = $event['name'];
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/events/events.css?v=6'); ?>">
<main class="event-page" data-event-id="<?php echo $id; ?>" data-item-url="<?php echo h(url_for('/events/item.php')); ?>">
    <header class="page-header">
        <p class="section-label"><a href="<?php echo url_for('/events/index.php'); ?>">Events</a></p>
        <h1><?php echo h($event['name']); ?></h1>
        <?php if ($dates !== '') { ?><p class="page-lede"><?php echo h($dates); ?></p><?php } ?>
        <?php if ($event['notes'] !== '') { ?><p class="event-notes"><?php echo h($event['notes']); ?></p><?php } ?>
        <p><a href="<?php echo url_for('/events/edit.php?id=' . $id); ?>">Edit event</a></p>
    </header>

    <section class="event-players" aria-labelledby="event-players-heading">
        <h2 id="event-players-heading">Players <small class="menu-support"><?php echo count($players); ?></small></h2>
        <?php if ($player_ages_label !== '') { ?>
            <p class="event-player-ages"><?php echo h($player_ages_label); ?><?php if ($event['starts_on']) { ?>
                <small class="menu-support">Ages as of <?php echo h(substr($event['starts_on'], 0, 4)); ?>, the year the event starts.</small><?php } ?></p>
        <?php } ?>
        <?php if ($players) { ?>
            <ul class="event-player-list">
                <?php foreach ($players as $player) { ?>
                    <li>
                        <form method="post" action="<?php echo url_for('/events/player.php'); ?>">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                            <input type="hidden" name="player_id" value="<?php echo $player['id']; ?>">
                            <span><a href="<?php echo url_for('/users/edit.php?id=' . $player['id']); ?>" target="_blank" rel="noopener"><?php echo h($player['name']); ?></a><?php if ($player['age'] !== null) { ?> <small class="menu-support">age <?php echo $player['age']; ?></small><?php } ?></span>
                            <button type="submit" name="action" value="remove" class="event-remove" aria-label="Remove <?php echo h($player['name']); ?> from this event">Remove</button>
                        </form>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <p class="menu-support">No players yet.</p>
        <?php } ?>
        <details class="event-add">
            <summary>Add players</summary>
            <?php if ($players_to_add) { ?>
                <form class="event-add-form" data-noun="player" method="post" action="<?php echo url_for('/events/player.php'); ?>">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="add">
                    <label for="event-player-filter">Find players</label>
                    <input type="search" id="event-player-filter" class="event-add-filter" placeholder="Search your people" autocomplete="off" aria-controls="event-player-list">
                    <div class="event-add-list" id="event-player-list">
                        <?php foreach ($players_to_add as $candidate) { ?>
                            <label class="event-choice" data-title="<?php echo h(mb_strtolower($candidate['name'])); ?>">
                                <input type="checkbox" name="player_ids[]" value="<?php echo $candidate['id']; ?>">
                                <span><?php echo h($candidate['name']); ?><?php if ($candidate['age'] !== null) { ?> <small class="menu-support">age <?php echo $candidate['age']; ?></small><?php } ?></span>
                            </label>
                        <?php } ?>
                    </div>
                    <p class="event-add-empty menu-support" hidden>No players match.</p>
                    <button type="submit" class="event-add-submit">Add selected players</button>
                </form>
            <?php } else { ?>
                <p class="menu-support"><?php echo $players ? 'Everyone in your people list is coming.' : 'Your people list is empty.'; ?>
                    <a href="<?php echo url_for('/users/new'); ?>">Create a user</a> to add someone new.</p>
            <?php } ?>
        </details>
    </section>

    <p class="event-total" aria-live="polite">
        <strong><?php echo count($items); ?></strong> <?php echo count($items) === 1 ? 'game' : 'games'; ?> planned<?php if ($items) { ?>,
        <strong id="event-packed-count"><?php echo $packed; ?></strong> packed<?php } ?><?php if ($not_kept > 0) { ?>,
        <strong><?php echo $not_kept; ?></strong> not kept<?php } ?>
    </p>

    <details class="event-add" <?php echo $items ? '' : 'open'; ?>>
        <summary>Add games from Keeplore</summary>
        <p class="menu-support">Games you don't keep are listed too, so a game you are thinking of buying can be tried in the plan. Add it on the Items page first if Keeplore doesn't have it yet.</p>
        <?php if ($to_add) { ?>
            <form class="event-add-form" data-noun="game" method="post" action="<?php echo url_for('/events/item.php'); ?>">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="add">
                <label for="event-add-filter">Find games</label>
                <input type="search" id="event-add-filter" class="event-add-filter" placeholder="Search your items" autocomplete="off" aria-controls="event-add-list">
                <label class="event-choice"><input type="checkbox" class="event-add-games-only" checked> Games only</label>
                <div class="event-add-list" id="event-add-list">
                    <?php foreach ($to_add as $candidate) { ?>
                        <label class="event-choice" data-game="<?php echo $candidate['is_game'] ? '1' : '0'; ?>" data-title="<?php echo h(mb_strtolower($candidate['Title'])); ?>">
                            <input type="checkbox" name="item_ids[]" value="<?php echo $candidate['id']; ?>">
                            <span><?php echo h($candidate['Title']); ?>
                                <?php if (!$candidate['is_kept']) { ?><span class="event-not-kept">Not kept</span><?php } ?>
                                <?php if ($candidate['facts'] !== '') { ?><small class="menu-support"><?php echo h($candidate['facts']); ?></small><?php } ?>
                            </span>
                        </label>
                    <?php } ?>
                </div>
                <p class="event-add-empty menu-support" hidden>No games match.</p>
                <button type="submit" class="event-add-submit">Add selected games</button>
            </form>
        <?php } else { ?>
            <p class="menu-support">Every item in Keeplore is already planned for this event.</p>
        <?php } ?>
    </details>

    <?php if ($items) { ?>
        <form class="event-grouping" method="get" action="<?php echo url_for('/events/show.php'); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <label>Group by
                <select name="by">
                    <?php foreach ($dimensions as $key => $label) { ?>
                        <option value="<?php echo h($key); ?>" <?php echo $by === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label>Then by
                <select name="then">
                    <?php foreach ($dimensions as $key => $label) { ?>
                        <option value="<?php echo h($key); ?>" <?php echo $then === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label>Only these tags
                <input type="text" name="tags" placeholder="casual, main" value="<?php echo h($tags); ?>" aria-describedby="event-tags-help">
            </label>
            <button type="submit">Group</button>
        </form>
        <p id="event-tags-help" class="menu-support">
            A game best at several player counts, or with several tags, shows in each of those groups.
            Players' ages makes a group for each child's age coming and one for adults, and puts each game under the youngest of them old enough for it.
            To split each player count into casual and main, tag games casual or main on Edit Item, group by Sweet spot, and enter “casual, main” here. Then by switches to Tag on its own, and other tags are left out.
        </p>

        <section class="event-plan" aria-label="Planned games">
            <?php foreach ($groups as $group) { ?>
                <div class="event-group">
                    <?php if ($group['label'] !== '') { ?>
                        <h2><?php echo h($group['label']); ?> <small class="menu-support"><?php echo count($group['items']); ?></small></h2>
                    <?php } ?>
                    <?php $subs = $group['groups'] ?: [['label' => '', 'items' => $group['items']]]; ?>
                    <?php foreach ($subs as $sub) { ?>
                        <?php if ($sub['label'] !== '') { ?><h3><?php echo h($sub['label']); ?></h3><?php } ?>
                        <ul class="event-checklist">
                            <?php foreach ($sub['items'] as $item) { ?>
                                <li>
                                    <div class="event-line">
                                        <input type="checkbox" class="event-packed" data-item-id="<?php echo $item['id']; ?>" aria-label="Packed: <?php echo h($item['Title']); ?>" <?php echo $item['is_packed'] ? 'checked' : ''; ?>>
                                        <span><a href="<?php echo url_for('/artifacts/edit.php?id=' . $item['id']); ?>" target="_blank" rel="noopener"><?php echo h($item['Title']); ?></a><?php echo h(event_plan_details($item)); ?></span>
                                        <form class="event-line-remove event-keep-scroll" method="post" action="<?php echo url_for('/events/item.php'); ?>">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" name="action" value="remove" class="event-remove" aria-label="Remove <?php echo h($item['Title']); ?> from this event">Remove</button>
                                        </form>
                                    </div>
                                    <?php if ($item['tags'] && !$by_tag) { ?><small class="menu-support"><?php echo h(implode(', ', $item['tags'])); ?></small><?php } ?>
                                </li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

        <details class="event-text">
            <summary>Plain-text list</summary>
            <textarea id="event-text" rows="12" readonly><?php echo h(event_plan_text($groups)); ?></textarea>
            <button type="button" id="event-text-copy">Copy list</button>
            <span id="event-text-status" class="menu-support" aria-live="polite"></span>
        </details>

        <section class="event-manage" aria-labelledby="event-manage-heading">
            <h2 id="event-manage-heading">Setting and notes</h2>
            <p class="menu-support">A setting, such as beach, groups games by where they will be played. A note, such as “requested by mom”, rides along on the list.</p>
            <datalist id="event-settings">
                <?php foreach ($settings as $setting) { ?><option value="<?php echo h($setting); ?>"><?php } ?>
            </datalist>
            <?php foreach ($items as $item) { ?>
                <form class="event-item-row" method="post" action="<?php echo url_for('/events/item.php'); ?>">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="event_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <a class="event-item-title" href="<?php echo url_for('/artifacts/edit.php?id=' . $item['id']); ?>" target="_blank" rel="noopener"><?php echo h($item['Title']); ?></a>
                    <label><span class="sr-only">Setting for <?php echo h($item['Title']); ?></span>
                        <input type="text" name="setting" maxlength="64" list="event-settings" placeholder="Setting" value="<?php echo h($item['setting']); ?>">
                    </label>
                    <label><span class="sr-only">Note for <?php echo h($item['Title']); ?></span>
                        <input type="text" name="note" maxlength="255" placeholder="Note" value="<?php echo h($item['note']); ?>">
                    </label>
                    <button type="submit" name="action" value="update">Save</button>
                    <button type="submit" name="action" value="remove" class="event-remove" aria-label="Remove <?php echo h($item['Title']); ?> from this event">Remove</button>
                </form>
            <?php } ?>
        </section>
    <?php } ?>
</main>
<form id="event-pack-form" hidden><?php echo csrf_input(); ?></form>
<script type="module" src="<?php echo url_for('/events/events.js?v=4'); ?>"></script>
<?php include(SHARED_PATH . '/footer.php'); ?>
