-- Marks an item_bgg_ratings row the owner entered by hand on Edit Item, so
-- the BGG import neither overwrites nor deletes it. Safe to rerun.
SET @keeplore_bgg_manual_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'item_bgg_ratings'
    AND COLUMN_NAME = 'is_manual'
);
SET @keeplore_bgg_manual_sql := IF(
  @keeplore_bgg_manual_exists = 0,
  'ALTER TABLE item_bgg_ratings ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER rated_at',
  'SELECT 1'
);
PREPARE keeplore_bgg_manual_stmt FROM @keeplore_bgg_manual_sql;
EXECUTE keeplore_bgg_manual_stmt;
DEALLOCATE PREPARE keeplore_bgg_manual_stmt;
