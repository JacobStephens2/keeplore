-- BoardGameGeek's ranked games with their community poll results, filled by
-- bin/refresh-bgg-poll-index so Search BGG can filter on them. Shared by all
-- owners: it describes BGG, not anyone's collection. Safe to rerun.
CREATE TABLE IF NOT EXISTS bgg_poll_games (
  thing_id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  year_published SMALLINT NULL DEFAULT NULL,
  bgg_rank INT UNSIGNED NULL DEFAULT NULL,
  average DECIMAL(6,4) NULL DEFAULT NULL,
  users_rated INT UNSIGNED NOT NULL DEFAULT 0,
  image_url VARCHAR(500) NULL DEFAULT NULL,
  subdomains VARCHAR(255) NOT NULL DEFAULT '',
  best_players VARCHAR(64) NOT NULL DEFAULT '',
  player_votes INT UNSIGNED NOT NULL DEFAULT 0,
  community_age TINYINT UNSIGNED NULL DEFAULT NULL,
  polls_fetched_at DATETIME NULL DEFAULT NULL,
  KEY idx_bgg_poll_games_age (community_age),
  KEY idx_bgg_poll_games_rank (bgg_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per player count the community voted Best. An open-ended "Best
-- with 9+" is stored as 9.
CREATE TABLE IF NOT EXISTS bgg_poll_best_players (
  thing_id INT UNSIGNED NOT NULL,
  players TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (thing_id, players),
  KEY idx_bgg_poll_best_players_players (players),
  CONSTRAINT fk_bgg_poll_best_players_game FOREIGN KEY (thing_id)
    REFERENCES bgg_poll_games (thing_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
