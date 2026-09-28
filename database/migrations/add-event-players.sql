-- The owner's players coming to an event. Safe to rerun; apply after
-- add-events.sql.
CREATE TABLE IF NOT EXISTS event_players (
  event_id INT UNSIGNED NOT NULL,
  player_id INT NOT NULL,
  PRIMARY KEY (event_id, player_id),
  KEY idx_event_players_player (player_id),
  CONSTRAINT fk_event_players_event FOREIGN KEY (event_id)
    REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
