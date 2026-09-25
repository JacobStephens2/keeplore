-- BoardGameGeek page link for an item. Safe to rerun.
SET @keeplore_bgg_url_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'games'
    AND COLUMN_NAME = 'bgg_url'
);
SET @keeplore_bgg_url_sql := IF(
  @keeplore_bgg_url_exists = 0,
  'ALTER TABLE games ADD COLUMN bgg_url VARCHAR(1024) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_bgg_url_stmt FROM @keeplore_bgg_url_sql;
EXECUTE keeplore_bgg_url_stmt;
DEALLOCATE PREPARE keeplore_bgg_url_stmt;
