-- Local login: username localdev / password LocalDev-12345
-- Guest browsing uses this same account (DEMO_USER_ID = 1).

INSERT INTO users (
  id, first_name, last_name, email, username, hashed_password, user_group,
  default_use_interval, default_snooze_days
)
SELECT 1, 'Local', 'Dev', 'local@keeplore.test', 'localdev',
  '$2y$12$wzBWQYlg7sYKC4KuOM.WTejyIk1FmnQZjTZt0i3lecjJjC0.T6OU2',
  1, 90, 7
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'localdev');

INSERT INTO players (id, user_id, FirstName, LastName, FullName, represents_user_id)
SELECT 1, 1, 'Local', 'Dev', 'Local Dev', 1
WHERE NOT EXISTS (SELECT 1 FROM players WHERE id = 1);

UPDATE users SET player_id = 1 WHERE id = 1 AND (player_id IS NULL OR player_id = 0);

INSERT INTO types (id, objectType, user_id)
SELECT 1, 'board-game', 1 WHERE NOT EXISTS (SELECT 1 FROM types WHERE id = 1);
INSERT INTO types (id, objectType, user_id)
SELECT 2, 'book', 1 WHERE NOT EXISTS (SELECT 1 FROM types WHERE id = 2);
INSERT INTO types (id, objectType, user_id)
SELECT 3, 'film', 1 WHERE NOT EXISTS (SELECT 1 FROM types WHERE id = 3);

INSERT INTO games (id, Title, Acq, type_id, type, user_id, is_kept, is_physical, is_digital, to_get_rid_of, interaction_frequency_days)
SELECT 1, 'Catan', '2024-01-15', 1, 'board-game', 1, 1, 1, 0, 0, 90
WHERE NOT EXISTS (SELECT 1 FROM games WHERE id = 1);
INSERT INTO games (id, Title, Acq, type_id, type, user_id, is_kept, is_physical, is_digital, to_get_rid_of)
SELECT 2, 'The Left Hand of Darkness', '2023-06-01', 2, 'book', 1, 1, 1, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM games WHERE id = 2);
INSERT INTO games (id, Title, Acq, type_id, type, user_id, is_kept, is_in_secondary_collection, is_physical, to_get_rid_of)
SELECT 3, 'Azul', '2022-11-20', 1, 'board-game', 1, 0, 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM games WHERE id = 3);

INSERT INTO uses (id, artifact_id, use_date, user_id, note)
SELECT 1, 1, DATE_SUB(CURDATE(), INTERVAL 120 DAY), 1, 'Kitchen table'
WHERE NOT EXISTS (SELECT 1 FROM uses WHERE id = 1);
