CREATE TABLE users (
    id INT PRIMARY KEY,
    default_use_interval INT DEFAULT 90
) ENGINE=InnoDB;
CREATE TABLE types (id INT PRIMARY KEY, objectType VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE games (
    id INT PRIMARY KEY,
    user_id INT NOT NULL,
    Title VARCHAR(255) NOT NULL,
    type_id INT,
    Candidate VARCHAR(255),
    ss VARCHAR(255),
    KeptCol TINYINT DEFAULT 1,
    InSecondaryCollection VARCHAR(10),
    is_kept TINYINT DEFAULT 1,
    is_in_secondary_collection TINYINT DEFAULT 0,
    is_digital TINYINT DEFAULT NULL,
    is_physical TINYINT DEFAULT NULL,
    to_get_rid_of TINYINT DEFAULT 0,
    Acq DATE DEFAULT '2026-01-01',
    interaction_frequency_days INT DEFAULT 90
) ENGINE=InnoDB;
CREATE TABLE players (
    id INT PRIMARY KEY,
    user_id INT NOT NULL,
    FirstName VARCHAR(255),
    LastName VARCHAR(255)
) ENGINE=InnoDB;
CREATE TABLE uses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    artifact_id INT NOT NULL,
    user_id INT NOT NULL,
    use_date DATE,
    note TEXT,
    notesTwo TEXT
) ENGINE=InnoDB;
CREATE TABLE responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    Title INT,
    user_id INT,
    PlayDate DATE
) ENGINE=InnoDB;
INSERT INTO users (id) VALUES (1), (2);
INSERT INTO types VALUES (1, 'board-game'), (2, 'film');
INSERT INTO games (id, user_id, Title, type_id, KeptCol, InSecondaryCollection, is_kept, is_in_secondary_collection, is_digital, is_physical, to_get_rid_of) VALUES
    (10, 1, 'Catan', 1, 1, NULL, 1, 0, NULL, NULL, 0),
    (11, 1, 'Azul', 1, 1, NULL, 1, 0, NULL, NULL, 1),
    (12, 1, 'Arrival', 2, 0, 'yes', 0, 1, NULL, NULL, 0),
    (13, 1, 'Former possession', 1, 0, NULL, 0, 0, NULL, NULL, 0),
    (20, 2, 'Private item', 1, 1, NULL, 1, 0, NULL, NULL, 0);
INSERT INTO players VALUES (100, 1, 'Sam', 'Lee'), (101, 1, 'Jo', 'Smith'), (200, 2, 'Other', 'Person');
INSERT INTO uses (artifact_id, user_id, use_date) VALUES (10, 1, '2026-02-01');
