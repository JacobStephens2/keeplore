-- A full import of an owner's BoardGameGeek reviewer, queued from Settings
-- and run by private/crons/run_bgg_import_jobs.php. Settings reads its
-- progress back while it runs. Safe to rerun.
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
