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
$values = $event;
if (is_post_request()) {
    try {
        $plans->save($_POST, $id);
        $_SESSION['message'] = 'Event updated.';
        redirect_to(url_for('/events/show.php?id=' . $id));
    } catch (InvalidArgumentException $error) {
        $errors[] = $error->getMessage();
        http_response_code(422);
        foreach (['name', 'starts_on', 'ends_on', 'notes'] as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
    } catch (OutOfBoundsException $error) {
        error_404();
    }
}
$page_title = 'Edit ' . $event['name'];
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/events/events.css?v=3'); ?>">
<main class="event-page">
    <header class="page-header">
        <p class="section-label"><a href="<?php echo url_for('/events/show.php?id=' . $id); ?>"><?php echo h($event['name']); ?></a></p>
        <h1>Edit event</h1>
    </header>
    <?php echo display_errors($errors); ?>
    <form class="event-form" method="post" action="<?php echo url_for('/events/edit.php?id=' . $id); ?>">
        <?php echo csrf_input(); ?>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" maxlength="255" required value="<?php echo h($values['name']); ?>">
        <div class="event-dates">
            <label>Starts <input type="date" name="starts_on" value="<?php echo h($values['starts_on'] ?? ''); ?>"></label>
            <label>Ends <input type="date" name="ends_on" value="<?php echo h($values['ends_on'] ?? ''); ?>"></label>
        </div>
        <label for="notes">Notes <span class="menu-support">(optional)</span></label>
        <textarea id="notes" name="notes" rows="4" placeholder="Where, who is coming, what others are bringing"><?php echo h($values['notes']); ?></textarea>
        <div class="event-actions">
            <button type="submit">Save event</button>
            <a href="<?php echo url_for('/events/show.php?id=' . $id); ?>">Cancel</a>
            <a class="event-delete-link" href="<?php echo url_for('/events/delete.php?id=' . $id); ?>">Delete event</a>
        </div>
    </form>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
