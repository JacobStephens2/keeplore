-- When an account's password last changed, as a unix time. A session or
-- access token issued before this no longer authenticates the account.
-- NULL means the password has not been reset. Safe to rerun.
SET @keeplore_password_changed_at_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'password_changed_at'
);
SET @keeplore_password_changed_at_sql := IF(
  @keeplore_password_changed_at_exists = 0,
  'ALTER TABLE users ADD COLUMN password_changed_at INT UNSIGNED NULL DEFAULT NULL AFTER hashed_password',
  'SELECT 1'
);
PREPARE keeplore_password_changed_at_stmt FROM @keeplore_password_changed_at_sql;
EXECUTE keeplore_password_changed_at_stmt;
DEALLOCATE PREPARE keeplore_password_changed_at_stmt;
