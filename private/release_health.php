<?php

/** Whether this release can read its required database schema, without changing it. */
function release_schema_is_ready(mysqli $db): bool
{
    // Add new migration dependencies here with the code that starts reading them.
    // LIMIT 0 resolves columns and tables without reading account data or running DDL.
    $requiredColumns = [
        'users' => 'password_changed_at, bgg_username, bgg_default_type_id, default_snooze_days,
            native_notify_enabled, native_notify_hour, native_notify_lead_days, native_notify_past_due',
        'games' => 'is_kept, is_in_secondary_collection, is_digital, is_physical, snoozed_until,
            image_url, bgg_url, bgg_player_votes, bgg_age_basis',
        'players' => 'birth_year',
        'agent_api_keys' => 'id, user_id, agent_name, key_hash, created_at, last_used_at, revoked_at',
        'item_tags' => 'id, user_id, artifact_id, tag',
        'item_bgg_ratings' => 'id, user_id, artifact_id, bgg_username, rating, comment, rated_at, is_manual, imported_at',
        'bgg_import_jobs' => 'id, user_id, bgg_username, status, total, checked, imported, removed, failed,
            error, created_at, started_at, finished_at, updated_at',
        'bgg_poll_games' => 'thing_id, name, year_published, bgg_rank, average, users_rated, image_url,
            subdomains, best_players, player_votes, community_age, polls_fetched_at',
        'bgg_poll_best_players' => 'thing_id, players, open_only',
        'proposal_outcomes' => 'id, user_id, item_id, proposal_date, outcome, note, chosen_item_id, chosen_item_name',
        'proposal_outcome_players' => 'proposal_id, player_id',
        'events' => 'id, user_id, name, starts_on, ends_on, notes',
        'event_items' => 'event_id, artifact_id, setting, note, is_packed',
        'event_players' => 'event_id, player_id',
    ];
    try {
        foreach ($requiredColumns as $table => $columns) {
            $result = $db->query("SELECT $columns FROM `$table` LIMIT 0");
            if ($result === false) {
                return false;
            }
            $result->free();
        }
        return true;
    } catch (mysqli_sql_exception $failure) {
        return false;
    }
}
