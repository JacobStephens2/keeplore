<?php

if (PHP_SAPI !== 'cli-server') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/private/dev_router.php';

$target = keeplore_ui_router_script($_SERVER['REQUEST_URI'] ?? '/', __DIR__);
if ($target === false) {
  return false;
}
if ($target === null) {
  http_response_code(404);
  echo 'Not found';
  return true;
}
require $target;
