<?php
require_once dirname(__DIR__, 2) . '/private/initialize.php';
require_login();
$player_set = find_players_by_user_id();
$page_title = 'Users';
include(SHARED_PATH . '/header.php');
?>

<main>
  <div class="objects listing">
    <header class="page-header page-header-row">
      <div>
        <p class="section-label">People</p>
        <h1>Users</h1>
        <p class="page-lede">People who appear on your interaction records.</p>
      </div>
      <div class="page-header-actions">
        <a class="prominent-link" href="<?php echo url_for('/users/new'); ?>">Create user</a>
      </div>
    </header>

    <label class="items-search-wrap">
      <span class="sr-only">Search users</span>
      <input type="search" id="users-search" class="items-search" data-shortcut="search" placeholder="Search by name" autocomplete="off" spellcheck="false" autofocus>
    </label>

    <?php if ($player_set->num_rows === 0) { ?>
      <div class="empty-state">
        <p class="section-label">Empty</p>
        <h2>No users yet</h2>
        <p>Add people so you can record who shared an interaction.</p>
        <a class="prominent-link" href="<?php echo url_for('/users/new'); ?>">Create user</a>
      </div>
    <?php } else { ?>
    <div class="table-scroll">
  	<table class="list" id="users" data-page-length='100'>

      <thead>
        <tr id="headerRow">
          <th data-sort="name" id="users-name-header">Name</th>
          <th data-sort="gender">Gender</th>
          <th data-sort="age">Age</th>
          <th></th>
          <th data-sort="id">ID</th>
        </tr>
      </thead>

      <tbody id="users-list-body">
        <?php while($player = mysqli_fetch_assoc($player_set)) { ?>
          <?php
            $user_name = trim($player['FirstName'] . ' ' . $player['LastName']);
            $user_age = $player['birth_year'] ? (date('Y') - (int) $player['birth_year']) : '';
          ?>
          <tr
            data-name="<?php echo h($user_name); ?>"
            data-gender="<?php echo h($player['G']); ?>"
            data-age="<?php echo h((string) $user_age); ?>"
            data-id="<?php echo h($player['id']); ?>"
          >
            <td>
              <a class="table-action" href="<?php echo url_for('/users/edit.php?id=' . h(u($player['id']))); ?>">
                <?php echo h($user_name); ?>
              </a>
            </td>
            <td><?php echo h($player['G']); ?></td>
            <td><?php echo h((string) $user_age); ?></td>
            <td><a class="table-action" href="<?php echo url_for('/users/delete.php?id=' . h(u($player['id']))); ?>">Delete</a></td>
            <td><?php echo h($player['id']); ?></td>
          </tr>
        <?php } ?>
      </tbody>

  	</table>
    </div>
    <div id="users-list-pager" class="items-list-pager"></div>
    <?php } ?>

    <script src="<?php echo url_for('/shared/js/users-list.js'); ?>?v=1"></script>
    <?php mysqli_free_result($player_set); ?>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
