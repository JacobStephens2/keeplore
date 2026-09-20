-- Owner tags on items. Distinct from BGG-imported taxonomy. Safe to rerun.
CREATE TABLE IF NOT EXISTS item_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  artifact_id INT NOT NULL,
  tag VARCHAR(64) NOT NULL,
  UNIQUE KEY uniq_item_tags_user_artifact_tag (user_id, artifact_id, tag),
  KEY idx_item_tags_user_tag (user_id, tag),
  KEY idx_item_tags_artifact (artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
