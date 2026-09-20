-- Cover picture URL stored from Request BGG Data. Safe to rerun.
SET @keeplore_image_url_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'games'
    AND COLUMN_NAME = 'image_url'
);
SET @keeplore_image_url_sql := IF(
  @keeplore_image_url_exists = 0,
  'ALTER TABLE games ADD COLUMN image_url VARCHAR(1024) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_image_url_stmt FROM @keeplore_image_url_sql;
EXECUTE keeplore_image_url_stmt;
DEALLOCATE PREPARE keeplore_image_url_stmt;
