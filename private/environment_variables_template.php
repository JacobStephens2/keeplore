<?php

define("DB_SERVER", "");
define("DB_USER", "");
define("DB_PASS", "");
define("DB_NAME", "");
define("DB_PORT", 3306);

// SMTP Configuration
define("SMTP_HOST", "");
define("SMTP_PORT", 587);
define("SMTP_USER", "");
define("SMTP_PASS", "");
define("SMTP_FROM_EMAIL", "");

define(
  "ARTIFACTS_API_KEY", 
  ""
);

define(
  "JWT_SECRET", 
  ""
);
define("COOKIE_SECURE", true);

// Product branding (emails, password reset, copy)
define("APP_NAME", "Keeplore");

// Canonical host for links, sessions, and JWT cookies (no scheme, no path).
// Production: keeplore.app — local dev: leave empty string "" for localhost.
define("ARTIFACTS_DOMAIN", "keeplore.app");
define("DOMAIN", ARTIFACTS_DOMAIN);
define("API_ORIGIN", "api." . ARTIFACTS_DOMAIN);
define("REQUEST_ORIGIN", ARTIFACTS_DOMAIN);

define("DEV_NAME", "Jacob Stephens");
define("DEV_EMAIL", "");

define("SWEET_SPOT_BUTTONS_ON", false);

define("DEMO_USER_ID", 0); // User ID whose data guests will browse

?>