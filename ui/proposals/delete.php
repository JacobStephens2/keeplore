<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/ProposalOutcomes.php');

$proposals = new ProposalOutcomes($db, (int) $_SESSION['user_id']);
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$record = $id ? $proposals->find($id) : null;
if ($record === null) {
    error_404();
}
$back = url_for('/artifacts/edit.php?id=' . $record['item_id'] . '#proposal-history');
if (is_post_request()) {
    try {
        $proposals->delete($id);
    } catch (OutOfBoundsException $error) {
        error_404();
    }
    $_SESSION['message'] = 'Proposal outcome deleted.';
    redirect_to($back);
}
$item = find_artifact_by_id($record['item_id']);
$page_title = 'Delete proposal outcome';
include(SHARED_PATH . '/header.php');
?>
<main>
    <h1>Delete proposal outcome</h1>
    <p>Delete this proposal outcome for <strong><?php echo h($item['Title'] ?? 'this item'); ?></strong> on <?php echo h($record['proposal_date']); ?>?</p>
    <p>Outcome: <?php echo h(ProposalOutcomes::OUTCOMES[$record['outcome']]); ?></p>
    <p>This will also remove it from the report counts.</p>
    <form method="post" action="<?php echo url_for('/proposals/delete.php?id=' . $id); ?>">
        <?php echo csrf_input(); ?>
        <button type="submit">Delete proposal outcome</button>
        <a href="<?php echo h($back); ?>">Cancel</a>
    </form>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
