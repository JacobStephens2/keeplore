<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/ProposalOutcomes.php');

$proposals = new ProposalOutcomes($db, (int) $_SESSION['user_id']);
$filters = [];
foreach (['start' => '', 'end' => '', 'sort' => 'explicit_declines', 'direction' => 'desc'] as $key => $default) {
    $filters[$key] = is_string($_GET[$key] ?? null) ? $_GET[$key] : $default;
}
$filters['include_other'] = ($_GET['include_other'] ?? '') === '1';
$rows = [];
try {
    $rows = $proposals->report($filters['start'], $filters['end'], $filters['include_other'], $filters['sort'], $filters['direction']);
} catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
    http_response_code(422);
}
$page_title = 'Proposal outcomes';
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/proposals/proposals.css'); ?>">
<main class="proposal-page">
    <header class="page-header">
        <p class="section-label"><a href="<?php echo url_for('/analysis.php'); ?>">Analysis</a></p>
        <h1>Proposal outcomes</h1>
        <p class="page-lede">See which items are declined most often, and which are passed over for something else.</p>
    </header>
    <p><a href="<?php echo url_for('/proposals/edit.php'); ?>">Record proposal outcome</a></p>
    <?php echo display_errors($errors); ?>
    <form class="proposal-filters" method="get">
        <label>From <input type="date" name="start" value="<?php echo h($filters['start']); ?>"></label>
        <label>Through <input type="date" name="end" value="<?php echo h($filters['end']); ?>"></label>
        <label class="proposal-choice"><input type="checkbox" name="include_other" value="1" <?php echo $filters['include_other'] ? 'checked' : ''; ?>> Include other tracked items</label>
        <input type="hidden" name="sort" value="<?php echo h($filters['sort']); ?>">
        <input type="hidden" name="direction" value="<?php echo h($filters['direction']); ?>">
        <button type="submit">Apply filters</button>
        <a href="<?php echo url_for('/proposals/index.php'); ?>">Reset</a>
    </form>
    <p class="menu-support">Each proposal counts once, regardless of the number of participants. Blank dates include all history. Currently kept items include both collections and items marked “Get Rid Of.”</p>
    <?php if (!$errors) { ?>
        <?php if (!$rows) { ?>
            <p>No items match this collection filter.</p>
        <?php } else { ?>
            <?php if (array_sum(array_column($rows, 'explicit_declines')) + array_sum(array_column($rows, 'chose_something_else')) === 0) { ?>
                <p>No proposal outcomes recorded for these items in this date range.</p>
            <?php } ?>
            <div class="proposal-table-wrap">
                <table class="list">
                    <caption class="menu-support">Select a count heading to rank items. Select an item to view its history.</caption>
                    <thead><tr>
                        <?php foreach (['item_name' => 'Item', 'explicit_declines' => 'Explicit declines', 'chose_something_else' => 'Chose something else'] as $key => $label) {
                            $active = $filters['sort'] === $key;
                            $next = $filters;
                            $next['sort'] = $key;
                            $next['direction'] = $active && $filters['direction'] === 'desc' ? 'asc' : ($key === 'item_name' && !$active ? 'asc' : 'desc');
                            $sortLabel = $active ? ($filters['direction'] === 'asc' ? 'ascending' : 'descending') : 'none';
                        ?>
                            <th scope="col" aria-sort="<?php echo $sortLabel; ?>"><a href="<?php echo h(url_for('/proposals/index.php?' . http_build_query($next))); ?>"><?php echo h($label); ?><?php echo $active ? ($filters['direction'] === 'asc' ? ' ↑' : ' ↓') : ''; ?></a></th>
                        <?php } ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <td><a href="<?php echo url_for('/artifacts/edit.php?id=' . $row['item_id'] . '#proposal-history'); ?>"><?php echo h($row['item_name']); ?></a><br><small><?php echo h($row['item_type']); ?></small></td>
                                <td><?php echo $row['explicit_declines']; ?></td>
                                <td><?php echo $row['chose_something_else']; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php } ?>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
