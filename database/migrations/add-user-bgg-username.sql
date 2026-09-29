-- The BoardGameGeek user whose ratings and comments an owner follows on their
-- items, set on Settings. It may be the owner's own BGG account or someone
-- else's, such as Gyges. Safe to rerun.
SET @keeplore_user_bgg_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'bgg_username'
);
SET @keeplore_user_bgg_sql := IF(
  @keeplore_user_bgg_exists = 0,
  'ALTER TABLE users ADD COLUMN bgg_username VARCHAR(64) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_user_bgg_stmt FROM @keeplore_user_bgg_sql;
EXECUTE keeplore_user_bgg_stmt;
DEALLOCATE PREPARE keeplore_user_bgg_stmt;
