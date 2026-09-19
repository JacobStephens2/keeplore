-- Deduplicate the uses_players junction and prevent recurrence
-- (issue #9, brief 2).
--
-- The write paths could link the same player to one play several times,
-- inflating headcounts. This collapses each (use_id, player_id) pair to
-- its earliest row and adds a uniqueness constraint on the pair.
--
-- Idempotent: the DELETE is a no-op when no duplicates remain, and the
-- ALTER is wrapped in the repo's INFORMATION_SCHEMA guard (see
-- add-indexes-for-useby.sql) so reruns skip the existing index.

DELETE up_dup
  FROM uses_players AS up_dup
  JOIN uses_players AS up_keep
    ON up_keep.use_id = up_dup.use_id
    AND up_keep.player_id = up_dup.player_id
    AND up_keep.id < up_dup.id;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'uses_players'
    AND index_name = 'uniq_uses_players_use_player'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE uses_players ADD UNIQUE INDEX uniq_uses_players_use_player (use_id, player_id)',
  'SELECT "uniq_uses_players_use_player already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
