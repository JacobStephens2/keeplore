-- Run before deploying proposal-outcome pages. Existing use/response rows are unchanged.
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
