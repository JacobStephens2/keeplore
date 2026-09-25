-- What an item's BGG numbers rest on: the player-count poll's total votes and
-- whether the minimum age came from the community poll or the publisher.
-- Safe to rerun.
SET @keeplore_bgg_votes_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'games'
    AND COLUMN_NAME = 'bgg_player_votes'
);
SET @keeplore_bgg_votes_sql := IF(
  @keeplore_bgg_votes_exists = 0,
  'ALTER TABLE games ADD COLUMN bgg_player_votes INT NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_bgg_votes_stmt FROM @keeplore_bgg_votes_sql;
EXECUTE keeplore_bgg_votes_stmt;
DEALLOCATE PREPARE keeplore_bgg_votes_stmt;

SET @keeplore_bgg_age_basis_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'games'
    AND COLUMN_NAME = 'bgg_age_basis'
);
SET @keeplore_bgg_age_basis_sql := IF(
  @keeplore_bgg_age_basis_exists = 0,
  'ALTER TABLE games ADD COLUMN bgg_age_basis VARCHAR(16) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE keeplore_bgg_age_basis_stmt FROM @keeplore_bgg_age_basis_sql;
EXECUTE keeplore_bgg_age_basis_stmt;
DEALLOCATE PREPARE keeplore_bgg_age_basis_stmt;
