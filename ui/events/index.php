<?php
require_once('../../private/initialize.php');
require_login();
require_once(PRIVATE_PATH . '/classes/EventPlans.php');
require_once(PRIVATE_PATH . '/event_plan.php');

$plans = new EventPlans($db, (int) $_SESSION['user_id']);
$values = ['name' => '', 'starts_on' => '', 'ends_on' => ''];
if (is_post_request()) {
    try {
        $id = $plans->save($_POST);
        $_SESSION['message'] = 'Event created. Add the games you plan to bring.';
        redirect_to(url_for('/events/show.php?id=' . $id));
    } catch (InvalidArgumentException $error) {
        $errors[] = $error->getMessage();
        http_response_code(422);
        foreach (array_keys($values) as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
    }
}
$events = $plans->all();
$page_title = 'Events';
include(SHARED_PATH . '/header.php');
?>
<link rel="stylesheet" href="<?php echo url_for('/events/events.css?v=3'); ?>">
<main class="event-page">
    <header class="page-header">
        <h1>Events</h1>
        <p class="page-lede">Plan which games to bring to a trip or a game day, then see them grouped by player count, age, setting or tag.</p>
    </header>
    <?php echo display_errors($errors); ?>
    <?php if ($events) { ?>
        <ul class="event-list">
            <?php foreach ($events as $event) { ?>
                <li>
                    <a href="<?php echo url_for('/events/show.php?id=' . $event['id']); ?>"><?php echo h($event['name']); ?></a>
                    <span class="menu-support">
                        <?php $dates = event_dates_label($event['starts_on'], $event['ends_on']); echo $dates === '' ? '' : h($dates) . ' · '; ?>
                        <?php echo $event['item_count']; ?> <?php echo $event['item_count'] === 1 ? 'game' : 'games'; ?><?php if ($event['packed_count'] > 0) { ?>, <?php echo $event['packed_count']; ?> packed<?php } ?>
                    </span>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <p>No events yet.</p>
    <?php } ?>
    <form class="event-form" method="post" action="<?php echo url_for('/events/index.php'); ?>">
        <?php echo csrf_input(); ?>
        <h2>New event</h2>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" maxlength="255" required placeholder="Beach week" value="<?php echo h($values['name']); ?>">
        <div class="event-dates">
            <label>Starts <input type="date" name="starts_on" value="<?php echo h($values['starts_on']); ?>"></label>
            <label>Ends <input type="date" name="ends_on" value="<?php echo h($values['ends_on']); ?>"></label>
        </div>
        <button type="submit">Create event</button>
    </form>
</main>
<?php include(SHARED_PATH . '/footer.php'); ?>
