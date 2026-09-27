<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/EventPlans.php');

$plans = new EventPlans($db, (int) $_SESSION['user_id']);
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$event = $id ? $plans->find($id) : null;
if ($event === null) {
    error_404();
}
if (is_post_request()) {
    try {
        $plans->delete($id);
    } catch (OutOfBoundsException $error) {
        error_404();
    }
    $_SESSION['message'] = 'Event deleted.';
    redirect_to(url_for('/events/index.php'));
}
$page_title = 'Delete ' . $event['name'];
include(SHARED_PATH . '/header.php');
?>
<main>
    <h1>Delete event</h1>
    <p>Delete <strong><?php echo h($event['name']); ?></strong> and its list of <?php echo count($event['items']); ?> planned games? The games stay in your collection.</p>
    <form method="post" action="<?php echo url_for('/events/delete.php?id=' . $id); ?>">
        <?php echo csrf_input(); ?>
        <button type="submit">Delete event</button>
        <a href="<?php echo url_for('/events/show.php?id=' . $id); ?>">Cancel</a>
    </form>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
