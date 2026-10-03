-- The type Create Item gives an item filled from BoardGameGeek data, such as
-- "table game", set on Settings. NULL keeps the form's own type. Safe to
-- rerun.
SET @keeplore_user_bgg_type_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'bgg_default_type_id'
);
SET @keeplore_user_bgg_type_sql := IF(
  @keeplore_user_bgg_type_exists = 0,
  'ALTER TABLE users ADD COLUMN bgg_default_type_id INT NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_user_bgg_type_stmt FROM @keeplore_user_bgg_type_sql;
EXECUTE keeplore_user_bgg_type_stmt;
DEALLOCATE PREPARE keeplore_user_bgg_type_stmt;
