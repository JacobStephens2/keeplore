-- Which Best player counts are only there because an open-ended vote runs
-- past the count the votes name. 0 means the votes name that count. Safe to
-- rerun: the column add is skipped when the fresh create already added it,
-- and the backfill sets the flag from Best text again.
SET @keeplore_bgg_open_only_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bgg_poll_best_players'
    AND COLUMN_NAME = 'open_only'
);
SET @keeplore_bgg_open_only_sql := IF(
  @keeplore_bgg_open_only_exists = 0,
  'ALTER TABLE bgg_poll_best_players ADD COLUMN open_only TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE keeplore_bgg_open_only_stmt FROM @keeplore_bgg_open_only_sql;
EXECUTE keeplore_bgg_open_only_stmt;
DEALLOCATE PREPARE keeplore_bgg_open_only_stmt;

-- The same rule Search BGG used before the flag: the last part of the Best
-- text is "N+" and the stored count is greater than N. "9+" marks 10 through
-- 20. "2, 4+" marks 5 through 20. "6-7" marks nothing, and clears a flag
-- that does not belong.
UPDATE bgg_poll_best_players b
JOIN bgg_poll_games g ON g.thing_id = b.thing_id
SET b.open_only = IF(
  g.best_players LIKE '%+'
    AND b.players > CAST(REPLACE(SUBSTRING_INDEX(g.best_players, ' ', -1), '+', '') AS UNSIGNED),
  1,
  0
);
