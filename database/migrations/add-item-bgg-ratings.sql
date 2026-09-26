-- A BoardGameGeek user's rating and comment on an owner's item, imported by
-- bin/import-bgg-ratings. One row per owner, item and BGG user. Safe to rerun.
CREATE TABLE IF NOT EXISTS item_bgg_ratings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  artifact_id INT NOT NULL,
  bgg_username VARCHAR(64) NOT NULL,
  rating DECIMAL(4,2) NULL DEFAULT NULL,
  comment TEXT NULL DEFAULT NULL,
  rated_at DATETIME NULL DEFAULT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_bgg_ratings_user_artifact_bgg_user (user_id, artifact_id, bgg_username),
  KEY idx_item_bgg_ratings_user_bgg_user (user_id, bgg_username),
  KEY idx_item_bgg_ratings_artifact (artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
