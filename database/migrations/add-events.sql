-- Events the owner plans which items to bring to, such as a beach week of
-- games. Each event item carries the event's own setting, note and packed
-- mark. Safe to rerun.
CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  starts_on DATE NULL DEFAULT NULL,
  ends_on DATE NULL DEFAULT NULL,
  notes TEXT NULL,
  KEY idx_events_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_items (
  event_id INT UNSIGNED NOT NULL,
  artifact_id INT NOT NULL,
  setting VARCHAR(64) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  is_packed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (event_id, artifact_id),
  KEY idx_event_items_artifact (artifact_id),
  CONSTRAINT fk_event_items_event FOREIGN KEY (event_id)
    REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
