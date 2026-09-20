<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/ProposalOutcomes.php');

$proposals = new ProposalOutcomes($db, (int) $_SESSION['user_id']);
$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($id === false) {
    error_404();
}
$record = $id === null ? null : $proposals->find($id);
if ($id !== null && $record === null) {
    error_404();
}
$items = find_artifacts_by_user()->fetch_all(MYSQLI_ASSOC);
usort($items, fn($a, $b) => strcasecmp($a['Title'], $b['Title']) ?: $a['id'] <=> $b['id']);
$itemsById = array_column($items, null, 'id');
$participants = list_players()->fetch_all(MYSQLI_ASSOC);
$initialItemId = $record['item_id'] ?? filter_var($_GET['item_id'] ?? '', FILTER_VALIDATE_INT);
if ($initialItemId && !isset($itemsById[$initialItemId])) {
    error_404();
}
$values = $record ?? [
    'item_id' => $initialItemId ?: '',
    'proposal_date' => (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d'),
    'outcome' => '',
    'note' => '',
    'chosen_item_id' => '',
    'chosen_item_name' => '',
];
$values['participant_ids'] = array_column($record['participants'] ?? [], 'id');

if (is_post_request()) {
    try {
        $savedId = $proposals->save($_POST, $id);
        $saved = $proposals->find($savedId);
        $_SESSION['message'] = $id === null ? 'Proposal outcome recorded.' : 'Proposal outcome updated.';
        redirect_to(url_for('/artifacts/edit.php?id=' . $saved['item_id'] . '#proposal-history'));
    } catch (InvalidArgumentException $error) {
        $errors[] = $error->getMessage();
        http_response_code(422);
    } catch (OutOfBoundsException $error) {
        error_404();
    }
    // Keep valid form values visible after an input error, including an empty participant selection.
    foreach (['item_id', 'proposal_date', 'outcome', 'note', 'chosen_item_id', 'chosen_item_name'] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $values['participant_ids'] = is_array($_POST['participant_ids'] ?? null)
        ? array_filter($_POST['participant_ids'], 'is_scalar') : [];
}

// If an alternative was deleted from the collection, retain its recorded name as free text.
if (!isset($itemsById[$values['chosen_item_id'] ?? ''])) {
    $values['chosen_item_id'] = '';
}
$page_title = $id === null ? 'Record proposal outcome' : 'Edit proposal outcome';
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/proposals/proposals.css?v=1'); ?>">
<main class="proposal-page">
    <header class="page-header">
        <p class="section-label">Proposal history</p>
        <h1><?php echo h($page_title); ?></h1>
        <p class="page-lede">Record what happened when you suggested using an item.</p>
    </header>
    <?php echo display_errors($errors); ?>
    <?php if (!$items) { ?>
        <p>Add an item before recording a proposal outcome.</p>
        <a href="<?php echo url_for('/artifacts/new.php'); ?>">Add an item</a>
    <?php } else { ?>
        <form class="proposal-form" method="post" action="<?php echo h(url_for('/proposals/edit.php' . ($id === null ? '' : '?id=' . $id))); ?>">
            <?php echo csrf_input(); ?>
            <label for="item_id">Proposed item</label>
            <select name="item_id" id="item_id" required>
                <option value="">Choose an item</option>
                <?php foreach ($items as $item) { ?>
                    <option value="<?php echo (int) $item['id']; ?>" <?php echo (string) $values['item_id'] === (string) $item['id'] ? 'selected' : ''; ?>><?php echo h($item['Title']); ?></option>
                <?php } ?>
            </select>
            <label for="proposal_date">Proposal date</label>
            <input type="date" id="proposal_date" name="proposal_date" required value="<?php echo h($values['proposal_date']); ?>">
            <fieldset>
                <legend>Outcome</legend>
                <?php foreach (ProposalOutcomes::OUTCOMES as $key => $label) { ?>
                    <label class="proposal-choice">
                        <input type="radio" name="outcome" value="<?php echo h($key); ?>" required <?php echo $values['outcome'] === $key ? 'checked' : ''; ?>>
                        <?php echo h($label); ?>
                    </label>
                <?php } ?>
                <p class="menu-support">If someone explicitly declined this item and the group then chose something else, select “Explicit decline.”</p>
            </fieldset>
            <fieldset>
                <legend>Item chosen instead <span class="menu-support">(optional)</span></legend>
                <?php
                    $chosenItemTitle = ($values['chosen_item_id'] ?? '') !== ''
                        ? $itemsById[$values['chosen_item_id']]['Title']
                        : '';
                ?>
                <label for="chosen_item_search">Choose an existing item</label>
                <input type="search" id="chosen_item_search"
                    value="<?php echo h($chosenItemTitle); ?>"
                    placeholder="Search items"
                    autocomplete="off"
                >
                <input type="hidden" name="chosen_item_id" id="chosen_item_id" value="<?php echo h($values['chosen_item_id'] ?? ''); ?>">
                <div id="chosen_item_results" class="searchResults" style="display: none;">
                    <ul id="chosen_item_results_list" class="searchResults"></ul>
                </div>
                <script type="application/json" id="chosen-item-options"><?php echo json_encode(
                    array_map(static fn($item) => ['id' => (int) $item['id'], 'label' => $item['Title']], $items),
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
                ); ?></script>
                <script type="module" src="<?php echo url_for('/proposals/edit.js?v=1'); ?>"></script>
                <label for="chosen_item_name">Or enter a name</label>
                <input type="text" id="chosen_item_name" name="chosen_item_name" maxlength="255" value="<?php echo h($values['chosen_item_id'] ? '' : $values['chosen_item_name']); ?>" aria-describedby="alternative-help">
                <p id="alternative-help" class="menu-support">An existing selection takes precedence over a typed name. A typed name does not add an item to your collection. Record actual use separately.</p>
            </fieldset>
            <fieldset>
                <legend>Participants <span class="menu-support">(optional)</span></legend>
                <div class="proposal-participants">
                    <?php foreach ($participants as $participant) { ?>
                        <label class="proposal-choice">
                            <input type="checkbox" name="participant_ids[]" value="<?php echo (int) $participant['id']; ?>" <?php echo in_array($participant['id'], $values['participant_ids']) ? 'checked' : ''; ?>>
                            <?php echo h(trim(($participant['FirstName'] ?? '') . ' ' . ($participant['LastName'] ?? ''))); ?>
                        </label>
                    <?php } ?>
                </div>
                <?php if (!$participants) { ?><p class="menu-support">You can record this without participants.</p><?php } ?>
            </fieldset>
            <label for="note">Note <span class="menu-support">(optional)</span></label>
            <textarea id="note" name="note" rows="4" placeholder="Reason, who objected, or other circumstances"><?php echo h($values['note']); ?></textarea>
            <div class="proposal-actions">
                <button type="submit">Save proposal outcome</button>
                <a href="<?php echo h(url_for(isset($itemsById[$values['item_id']]) ? '/artifacts/edit.php?id=' . (int) $values['item_id'] . '#proposal-history' : '/proposals/index.php')); ?>">Cancel</a>
            </div>
        </form>
    <?php } ?>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
