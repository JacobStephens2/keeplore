<?php

/**
 * Record-use write seam.
 *
 * Pages that record a use (Record Use, Interact By, dashboard, Edit User)
 * share this module for the AJAX body, the post-save redirect, who is
 * listed as a participant, and the default Setting value.
 */

function record_use_success_message(array $post): string
{
    $user_count = count($post['user'] ?? []);
    $user_count_word = $user_count === 1 ? 'person' : 'people';
    return 'The interaction with ' . ($post['artifact']['name'] ?? '')
        . " with $user_count $user_count_word was recorded.";
}

function record_use_ajax_payload(array $post, int $use_id, array $status, $artifact_row): array
{
    return [
        'ok' => true,
        'message' => record_use_success_message($post),
        'artifact_id' => (int) ($post['artifact']['id'] ?? 0),
        'artifact_name' => $post['artifact']['name'] ?? '',
        'artifact_type' => is_array($artifact_row) ? ($artifact_row['type'] ?? '') : '',
        'use_id' => $use_id,
        'use_date' => $post['useDate'] ?? '',
        'new_use_by_date' => $status['use_by_date'] ?? null,
        'most_recent_use_date' => $status['most_recent_use_date'] ?? null,
        'is_overdue' => $status['is_overdue'] ?? false,
    ];
}

function record_use_return_path(array $post, string $default_path): string
{
    $return_player_id = (int) ($post['return_player_id'] ?? 0);
    if (($post['return_to'] ?? '') === 'user-edit' && $return_player_id > 0) {
        return url_for('/users/edit.php?id=' . $return_player_id);
    }
    return $default_path;
}

function record_use_participants(
    int $player_id,
    string $player_name,
    int $session_player_id,
    string $session_name
): array {
    $people = [
        ['id' => $player_id, 'name' => $player_name],
    ];
    if ($session_player_id > 0 && $session_player_id !== $player_id) {
        $people[] = ['id' => $session_player_id, 'name' => $session_name];
    }
    return $people;
}

function most_recent_use_setting(int $user_id, callable $query = null): string
{
    $query = $query ?? 'singleValueQuery';
    $user_id = (int) $user_id;
    $note = $query(
        "SELECT note FROM uses WHERE user_id = '" . $user_id . "' ORDER BY id DESC LIMIT 1"
    );
    if ($note === null || $note === false || $note === 'No results' || $note === 'Possible query error') {
        $note = $query(
            "SELECT default_setting FROM users WHERE id = '" . $user_id . "' LIMIT 1"
        );
    }
    if ($note === null || $note === false || $note === 'No results' || $note === 'Possible query error') {
        return '';
    }
    return (string) $note;
}
