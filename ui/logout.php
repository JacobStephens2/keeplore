<?php
require_once('../private/initialize.php');

$refusal = run_guest_or_logout('logout');
if ($refusal !== null) {
  http_response_code($refusal[0]);
  exit($refusal[1]['message']);
}

redirect_to(url_for('/login.php'));

?>
