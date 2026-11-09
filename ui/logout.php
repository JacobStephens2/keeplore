<?php
require_once('../private/initialize.php');

exit_on_guest_or_logout_refusal('logout');

redirect_to(url_for('/login.php'));

?>
