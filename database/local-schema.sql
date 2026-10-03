-- Current-shape schema for local development (post-migrations).
-- Safe to rerun: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NOT NULL,
  last_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  username VARCHAR(255) NOT NULL,
  hashed_password VARCHAR(255) NOT NULL,
  user_group INT DEFAULT 1,
  default_use_interval DECIMAL(8,2) DEFAULT 90,
  default_snooze_days INT NOT NULL DEFAULT 7,
  default_setting VARCHAR(255) DEFAULT NULL,
  daily_email TINYINT(1) NOT NULL DEFAULT 1,
  daily_email_hour TINYINT UNSIGNED NOT NULL DEFAULT 8,
  native_notify_enabled TINYINT(1) NOT NULL DEFAULT 1,
  native_notify_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
  native_notify_lead_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  native_notify_past_due TINYINT(1) NOT NULL DEFAULT 1,
  player_id INT DEFAULT NULL,
  bgg_username VARCHAR(64) DEFAULT NULL,
  bgg_default_type_id INT DEFAULT NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  objectType VARCHAR(100) NOT NULL,
  user_id INT DEFAULT NULL,
  KEY idx_types_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  Title VARCHAR(255) NOT NULL,
  FullTitle VARCHAR(255) DEFAULT NULL,
  Notes TEXT,
  image_url VARCHAR(1024) DEFAULT NULL,
  bgg_url VARCHAR(1024) DEFAULT NULL,
  bgg_player_votes INT DEFAULT NULL,
  bgg_age_basis VARCHAR(16) DEFAULT NULL,
  Acq DATE DEFAULT NULL,
  type_id INT DEFAULT NULL,
  type VARCHAR(100) DEFAULT NULL,
  user_id INT NOT NULL,
  is_kept TINYINT(1) DEFAULT 1,
  is_in_secondary_collection TINYINT(1) NOT NULL DEFAULT 0,
  is_digital TINYINT(1) DEFAULT NULL,
  is_physical TINYINT(1) DEFAULT NULL,
  to_get_rid_of TINYINT(1) NOT NULL DEFAULT 0,
  snoozed_until DATE DEFAULT NULL,
  Candidate VARCHAR(255) DEFAULT NULL,
  CandidateGroupDate DATE DEFAULT NULL,
  UsedRecUserCt VARCHAR(50) DEFAULT NULL,
  SS VARCHAR(255) DEFAULT NULL,
  MnT INT DEFAULT NULL,
  MxT INT DEFAULT NULL,
  MnP INT DEFAULT NULL,
  MxP INT DEFAULT NULL,
  Age INT DEFAULT NULL,
  age_max INT DEFAULT NULL,
  Wt VARCHAR(50) DEFAULT NULL,
  Yr DOUBLE DEFAULT NULL,
  Av VARCHAR(50) DEFAULT NULL,
  BGG_Rat VARCHAR(10) DEFAULT NULL,
  FavCt INT DEFAULT NULL,
  `Access` VARCHAR(100) DEFAULT NULL,
  OrigPlat VARCHAR(100) DEFAULT NULL,
  `System` VARCHAR(100) DEFAULT NULL,
  interaction_frequency_days DECIMAL(8,2) DEFAULT NULL,
  KEY idx_games_user (user_id),
  KEY idx_games_type (type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  FirstName VARCHAR(255) DEFAULT NULL,
  LastName VARCHAR(255) DEFAULT NULL,
  FullName VARCHAR(255) DEFAULT NULL,
  G VARCHAR(10) DEFAULT NULL,
  birth_year INT DEFAULT NULL,
  Priority INT DEFAULT NULL,
  MenuPriority INT DEFAULT NULL,
  represents_user_id INT DEFAULT NULL,
  KEY idx_players_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  artifact_id INT NOT NULL,
  use_date DATE DEFAULT NULL,
  user_id INT NOT NULL,
  note TEXT,
  notesTwo TEXT,
  KEY idx_uses_artifact (artifact_id),
  KEY idx_uses_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uses_players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  use_id INT NOT NULL,
  player_id INT NOT NULL,
  user_id INT NOT NULL,
  KEY idx_uses_players_use (use_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  Title INT NOT NULL,
  Player INT DEFAULT NULL,
  PlayDate DATE DEFAULT NULL,
  AversionDate DATE DEFAULT NULL,
  PassDate DATE DEFAULT NULL,
  RequestDate DATE DEFAULT NULL,
  Note TEXT,
  user_id INT NOT NULL,
  KEY idx_responses_title (Title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proposal_outcomes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  proposal_date DATE NOT NULL,
  outcome ENUM('explicit_decline', 'chose_something_else') NOT NULL,
  note TEXT NOT NULL,
  chosen_item_id INT NULL,
  chosen_item_name VARCHAR(255) NOT NULL DEFAULT '',
  INDEX idx_proposals_user_item_date (user_id, item_id, proposal_date),
  INDEX idx_proposals_user_date (user_id, proposal_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proposal_outcome_players (
  proposal_id INT UNSIGNED NOT NULL,
  player_id INT NOT NULL,
  PRIMARY KEY (proposal_id, player_id),
  CONSTRAINT fk_proposal_players_outcome FOREIGN KEY (proposal_id)
    REFERENCES proposal_outcomes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sweetspots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  Title INT NOT NULL,
  SwS VARCHAR(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS playgroup (
  ID INT AUTO_INCREMENT PRIMARY KEY,
  FullName INT NOT NULL,
  user_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  artifact_id INT NOT NULL,
  tag VARCHAR(64) NOT NULL,
  UNIQUE KEY uniq_item_tags_user_artifact_tag (user_id, artifact_id, tag),
  KEY idx_item_tags_user_tag (user_id, tag),
  KEY idx_item_tags_artifact (artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_bgg_ratings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  artifact_id INT NOT NULL,
  bgg_username VARCHAR(64) NOT NULL,
  rating DECIMAL(4,2) NULL DEFAULT NULL,
  comment TEXT NULL DEFAULT NULL,
  rated_at DATETIME NULL DEFAULT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_bgg_ratings_user_artifact_bgg_user (user_id, artifact_id, bgg_username),
  KEY idx_item_bgg_ratings_user_bgg_user (user_id, bgg_username),
  KEY idx_item_bgg_ratings_artifact (artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bgg_import_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  bgg_username VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued',
  total INT UNSIGNED NULL DEFAULT NULL,
  checked INT UNSIGNED NOT NULL DEFAULT 0,
  imported INT UNSIGNED NOT NULL DEFAULT 0,
  removed INT UNSIGNED NOT NULL DEFAULT 0,
  failed INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL DEFAULT NULL,
  finished_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bgg_import_jobs_user (user_id, id),
  KEY idx_bgg_import_jobs_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS agent_api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  agent_name VARCHAR(100) NOT NULL,
  key_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_agent_api_keys_hash (key_hash),
  KEY idx_agent_api_keys_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_endpoint_time (ip_address, endpoint, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS bgg_poll_best_players (
  thing_id INT UNSIGNED NOT NULL,
  players TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (thing_id, players),
  KEY idx_bgg_poll_best_players_players (players),
  CONSTRAINT fk_bgg_poll_best_players_game FOREIGN KEY (thing_id)
    REFERENCES bgg_poll_games (thing_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
