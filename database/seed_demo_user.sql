-- Seed script: creates a demo user with sample artifacts and interactions
-- for the guest browsing mode.
--
-- Usage:
--   mysql -u <user> -p <database> < database/seed_demo_user.sql
--
-- After running, note the @demo_user_id printed at the end and update
-- DEMO_USER_ID in private/environment_variables.php to match.

-- ============================================================
-- 1. Demo user
-- ============================================================
INSERT INTO users (first_name, last_name, email, username, hashed_password, user_group, default_use_interval)
VALUES ('Demo', 'User', 'demo@artifact.example', 'demouser',
        '$2y$10$XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', -- unusable bcrypt hash
        1, 90);

SET @demo_user_id = LAST_INSERT_ID();

-- ============================================================
-- 2. Types (scoped to demo user)
-- ============================================================
INSERT INTO types (objectType, user_id) VALUES
  ('book',        @demo_user_id),
  ('board-game',  @demo_user_id),
  ('film',        @demo_user_id),
  ('equipment',   @demo_user_id),
  ('instrument',  @demo_user_id),
  ('food',        @demo_user_id),
  ('drink',       @demo_user_id);

SET @type_book       = (SELECT id FROM types WHERE objectType = 'book'       AND user_id = @demo_user_id LIMIT 1);
SET @type_boardgame  = (SELECT id FROM types WHERE objectType = 'board-game' AND user_id = @demo_user_id LIMIT 1);
SET @type_film       = (SELECT id FROM types WHERE objectType = 'film'       AND user_id = @demo_user_id LIMIT 1);
SET @type_equipment  = (SELECT id FROM types WHERE objectType = 'equipment'  AND user_id = @demo_user_id LIMIT 1);
SET @type_instrument = (SELECT id FROM types WHERE objectType = 'instrument' AND user_id = @demo_user_id LIMIT 1);
SET @type_food       = (SELECT id FROM types WHERE objectType = 'food'       AND user_id = @demo_user_id LIMIT 1);
SET @type_drink      = (SELECT id FROM types WHERE objectType = 'drink'      AND user_id = @demo_user_id LIMIT 1);

-- ============================================================
-- 3. Demo player (represents the demo user)
-- ============================================================
INSERT INTO players (user_id, FirstName, LastName)
VALUES (@demo_user_id, 'Demo', 'User');

SET @demo_player_id = LAST_INSERT_ID();

-- Link player to user
UPDATE users SET player_id = @demo_player_id WHERE id = @demo_user_id;

-- ============================================================
-- 4. Artifacts (games table)
--    Mix of types, interaction states, and frequencies.
--    Dates are relative to "today" so the demo stays fresh.
-- ============================================================

-- Books (5) -----------------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, interaction_frequency_days, to_get_rid_of)
VALUES
  ('Dune',                     @demo_user_id, @type_book, 'book', DATE_SUB(CURDATE(), INTERVAL 400 DAY), 1, 0, NULL, NULL, 90,   0),
  ('The Hobbit',               @demo_user_id, @type_book, 'book', DATE_SUB(CURDATE(), INTERVAL 300 DAY), 1, 0, NULL, NULL, NULL, 0),
  ('Atomic Habits',            @demo_user_id, @type_book, 'book', DATE_SUB(CURDATE(), INTERVAL 200 DAY), 1, 0, NULL, NULL, 60,   0),
  ('Deep Work',                @demo_user_id, @type_book, 'book', DATE_SUB(CURDATE(), INTERVAL 150 DAY), 1, 0, NULL, NULL, 90,   0),
  ('The Great Gatsby',         @demo_user_id, @type_book, 'book', DATE_SUB(CURDATE(), INTERVAL 500 DAY), 1, 0, NULL, NULL, 90,   1);

SET @a_dune          = (SELECT id FROM games WHERE Title = 'Dune'             AND user_id = @demo_user_id LIMIT 1);
SET @a_hobbit        = (SELECT id FROM games WHERE Title = 'The Hobbit'       AND user_id = @demo_user_id LIMIT 1);
SET @a_atomic        = (SELECT id FROM games WHERE Title = 'Atomic Habits'    AND user_id = @demo_user_id LIMIT 1);
SET @a_deepwork      = (SELECT id FROM games WHERE Title = 'Deep Work'        AND user_id = @demo_user_id LIMIT 1);
SET @a_gatsby        = (SELECT id FROM games WHERE Title = 'The Great Gatsby' AND user_id = @demo_user_id LIMIT 1);

-- Board games (4) ----------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, mnp, mxp, mnt, mxt, interaction_frequency_days, to_get_rid_of)
VALUES
  ('Catan',                    @demo_user_id, @type_boardgame, 'board-game', DATE_SUB(CURDATE(), INTERVAL 365 DAY), 1, 0, NULL, NULL, 3, 4, 60, 120, 60,   0),
  ('Ticket to Ride',           @demo_user_id, @type_boardgame, 'board-game', DATE_SUB(CURDATE(), INTERVAL 250 DAY), 1, 0, NULL, NULL, 2, 5, 30,  60, 90,   0),
  ('Wingspan',                 @demo_user_id, @type_boardgame, 'board-game', DATE_SUB(CURDATE(), INTERVAL 180 DAY), 1, 0, NULL, NULL, 1, 5, 40,  70, NULL, 0),
  ('Monopoly',                 @demo_user_id, @type_boardgame, 'board-game', DATE_SUB(CURDATE(), INTERVAL 600 DAY), 1, 0, NULL, NULL, 2, 8, 60, 180, 90,   1);

SET @a_catan         = (SELECT id FROM games WHERE Title = 'Catan'           AND user_id = @demo_user_id LIMIT 1);
SET @a_ticket        = (SELECT id FROM games WHERE Title = 'Ticket to Ride'  AND user_id = @demo_user_id LIMIT 1);
SET @a_wingspan      = (SELECT id FROM games WHERE Title = 'Wingspan'        AND user_id = @demo_user_id LIMIT 1);
SET @a_monopoly      = (SELECT id FROM games WHERE Title = 'Monopoly'        AND user_id = @demo_user_id LIMIT 1);

-- Films (3) -----------------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, interaction_frequency_days, to_get_rid_of)
VALUES
  ('The Shawshank Redemption', @demo_user_id, @type_film, 'film', DATE_SUB(CURDATE(), INTERVAL 350 DAY), 1, 0, NULL, NULL, 120,  0),
  ('Spirited Away',            @demo_user_id, @type_film, 'film', DATE_SUB(CURDATE(), INTERVAL 200 DAY), 1, 0, NULL, NULL, 90,   0),
  ('The Grand Budapest Hotel', @demo_user_id, @type_film, 'film', DATE_SUB(CURDATE(), INTERVAL 100 DAY), 1, 0, NULL, NULL, NULL, 0);

SET @a_shawshank     = (SELECT id FROM games WHERE Title = 'The Shawshank Redemption' AND user_id = @demo_user_id LIMIT 1);
SET @a_spirited      = (SELECT id FROM games WHERE Title = 'Spirited Away'             AND user_id = @demo_user_id LIMIT 1);
SET @a_budapest      = (SELECT id FROM games WHERE Title = 'The Grand Budapest Hotel'  AND user_id = @demo_user_id LIMIT 1);

-- Equipment (3) -------------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, interaction_frequency_days, to_get_rid_of)
VALUES
  ('Cast Iron Skillet',        @demo_user_id, @type_equipment, 'equipment', DATE_SUB(CURDATE(), INTERVAL 500 DAY), 1, 0, NULL, NULL, 30,   0),
  ('Camping Tent',             @demo_user_id, @type_equipment, 'equipment', DATE_SUB(CURDATE(), INTERVAL 400 DAY), 1, 0, NULL, NULL, 120,  0),
  ('Stand Mixer',              @demo_user_id, @type_equipment, 'equipment', DATE_SUB(CURDATE(), INTERVAL 250 DAY), 1, 0, NULL, NULL, 60,   0);

SET @a_skillet       = (SELECT id FROM games WHERE Title = 'Cast Iron Skillet' AND user_id = @demo_user_id LIMIT 1);
SET @a_tent          = (SELECT id FROM games WHERE Title = 'Camping Tent'      AND user_id = @demo_user_id LIMIT 1);
SET @a_mixer         = (SELECT id FROM games WHERE Title = 'Stand Mixer'       AND user_id = @demo_user_id LIMIT 1);

-- Instrument (1) ------------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, interaction_frequency_days, to_get_rid_of)
VALUES
  ('Acoustic Guitar',          @demo_user_id, @type_instrument, 'instrument', DATE_SUB(CURDATE(), INTERVAL 700 DAY), 1, 0, NULL, NULL, 30, 0);

SET @a_guitar        = (SELECT id FROM games WHERE Title = 'Acoustic Guitar' AND user_id = @demo_user_id LIMIT 1);

-- Food & Drink (2) ----------------------------------------
INSERT INTO games (Title, user_id, type_id, type, Acq, is_kept, is_in_secondary_collection, is_digital, is_physical, interaction_frequency_days, to_get_rid_of)
VALUES
  ('Sourdough Starter',        @demo_user_id, @type_food,  'food',  DATE_SUB(CURDATE(), INTERVAL 300 DAY), 1, 0, NULL, NULL, 14, 0),
  ('French Press',             @demo_user_id, @type_drink, 'drink', DATE_SUB(CURDATE(), INTERVAL 200 DAY), 1, 0, NULL, NULL, 7,  0);

SET @a_sourdough     = (SELECT id FROM games WHERE Title = 'Sourdough Starter' AND user_id = @demo_user_id LIMIT 1);
SET @a_frenchpress   = (SELECT id FROM games WHERE Title = 'French Press'      AND user_id = @demo_user_id LIMIT 1);

-- ============================================================
-- 5. Interactions (uses table)
--    Spread over the past year to create varied use-by states.
-- ============================================================
INSERT INTO uses (artifact_id, use_date, user_id, note) VALUES
  -- Dune: last used 120 days ago -> overdue at 90-day interval
  (@a_dune,        DATE_SUB(CURDATE(), INTERVAL 120 DAY), @demo_user_id, 'Re-read chapters 1-10'),
  (@a_dune,        DATE_SUB(CURDATE(), INTERVAL 300 DAY), @demo_user_id, 'First read'),

  -- The Hobbit: last used 50 days ago -> not yet due at 90-day default
  (@a_hobbit,      DATE_SUB(CURDATE(), INTERVAL 50 DAY),  @demo_user_id, 'Read to chapter 8'),
  (@a_hobbit,      DATE_SUB(CURDATE(), INTERVAL 200 DAY), @demo_user_id, 'Started reading'),

  -- Atomic Habits: last used 80 days ago -> overdue at 60-day interval
  (@a_atomic,      DATE_SUB(CURDATE(), INTERVAL 80 DAY),  @demo_user_id, 'Reviewed habit tracker'),
  (@a_atomic,      DATE_SUB(CURDATE(), INTERVAL 160 DAY), @demo_user_id, 'Read chapters 5-8'),

  -- Deep Work: last used 10 days ago -> recently used
  (@a_deepwork,    DATE_SUB(CURDATE(), INTERVAL 10 DAY),  @demo_user_id, 'Applied deep work blocks'),

  -- Catan: last used 100 days ago -> overdue at 60-day interval
  (@a_catan,       DATE_SUB(CURDATE(), INTERVAL 100 DAY), @demo_user_id, 'Game night with friends'),
  (@a_catan,       DATE_SUB(CURDATE(), INTERVAL 200 DAY), @demo_user_id, 'Family game night'),
  (@a_catan,       DATE_SUB(CURDATE(), INTERVAL 320 DAY), @demo_user_id, 'First play'),

  -- Ticket to Ride: last used 30 days ago -> not yet due
  (@a_ticket,      DATE_SUB(CURDATE(), INTERVAL 30 DAY),  @demo_user_id, 'Europe map'),
  (@a_ticket,      DATE_SUB(CURDATE(), INTERVAL 150 DAY), @demo_user_id, 'USA map'),

  -- Wingspan: never used -> will be overdue since acquisition

  -- Shawshank: last used 200 days ago -> overdue at 120-day interval
  (@a_shawshank,   DATE_SUB(CURDATE(), INTERVAL 200 DAY), @demo_user_id, 'Movie night'),

  -- Spirited Away: last used 40 days ago -> not yet due
  (@a_spirited,    DATE_SUB(CURDATE(), INTERVAL 40 DAY),  @demo_user_id, 'Watched with family'),

  -- Grand Budapest Hotel: never used -> due based on acquisition

  -- Cast Iron Skillet: last used 5 days ago -> very recent
  (@a_skillet,     DATE_SUB(CURDATE(), INTERVAL 5 DAY),   @demo_user_id, 'Made cornbread'),
  (@a_skillet,     DATE_SUB(CURDATE(), INTERVAL 20 DAY),  @demo_user_id, 'Seared steaks'),
  (@a_skillet,     DATE_SUB(CURDATE(), INTERVAL 35 DAY),  @demo_user_id, 'Fried eggs'),

  -- Camping Tent: last used 150 days ago -> overdue at 120-day interval
  (@a_tent,        DATE_SUB(CURDATE(), INTERVAL 150 DAY), @demo_user_id, 'Weekend camping trip'),

  -- Stand Mixer: last used 45 days ago -> not yet due at 60-day interval
  (@a_mixer,       DATE_SUB(CURDATE(), INTERVAL 45 DAY),  @demo_user_id, 'Baked bread'),
  (@a_mixer,       DATE_SUB(CURDATE(), INTERVAL 100 DAY), @demo_user_id, 'Made pasta dough'),

  -- Acoustic Guitar: last used 60 days ago -> overdue at 30-day interval
  (@a_guitar,      DATE_SUB(CURDATE(), INTERVAL 60 DAY),  @demo_user_id, 'Practiced chords'),
  (@a_guitar,      DATE_SUB(CURDATE(), INTERVAL 120 DAY), @demo_user_id, 'Learned new song'),
  (@a_guitar,      DATE_SUB(CURDATE(), INTERVAL 250 DAY), @demo_user_id, 'Jam session'),

  -- Sourdough Starter: last used 3 days ago -> very recent
  (@a_sourdough,   DATE_SUB(CURDATE(), INTERVAL 3 DAY),   @demo_user_id, 'Fed starter, baked loaf'),
  (@a_sourdough,   DATE_SUB(CURDATE(), INTERVAL 10 DAY),  @demo_user_id, 'Made pizza dough'),

  -- French Press: last used 1 day ago -> very recent
  (@a_frenchpress, DATE_SUB(CURDATE(), INTERVAL 1 DAY),   @demo_user_id, 'Morning coffee'),
  (@a_frenchpress, DATE_SUB(CURDATE(), INTERVAL 4 DAY),   @demo_user_id, 'Afternoon coffee');

-- ============================================================
-- 6. Link interactions to demo player (uses_players)
-- ============================================================
INSERT INTO uses_players (use_id, player_id, user_id)
SELECT id, @demo_player_id, @demo_user_id FROM uses WHERE user_id = @demo_user_id;

-- ============================================================
-- 7. Print the new demo user ID
-- ============================================================
SELECT @demo_user_id AS demo_user_id;
