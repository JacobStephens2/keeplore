<?php

// Copied to environment_variables.php by bin/dev. Not used in production.

define("DB_SERVER", "127.0.0.1");
define("DB_USER", "keeplore");
define("DB_PASS", "keeplore");
define("DB_NAME", "keeplore");
define("DB_PORT", 3306);

define("SMTP_HOST", "");
define("SMTP_PORT", 587);
define("SMTP_USER", "");
define("SMTP_PASS", "");
define("SMTP_FROM_EMAIL", "");

define("ARTIFACTS_API_KEY", "local-dev-api-key");
define("JWT_SECRET", "local-dev-jwt-secret-not-for-production");
define("COOKIE_SECURE", false);

define("APP_NAME", "Keeplore");
define("DEV_NAME", "Jacob Stephens");
define("DEV_EMAIL", "jacob@stephens.page");

// Empty domain = host-only cookies so login works on 127.0.0.1.
define("ARTIFACTS_DOMAIN", "");
define("DOMAIN", ARTIFACTS_DOMAIN);
define("API_ORIGIN", "127.0.0.1:8788");
define("REQUEST_ORIGIN", "127.0.0.1:8787");

define("SWEET_SPOT_BUTTONS_ON", false);
define("DEMO_USER_ID", 1);

?>
