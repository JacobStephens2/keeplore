<?php

/**
 * Record-use write seam.
 *
 * Pages that record a use (Record Use, Interact By, dashboard, Edit User)
 * share this module for the AJAX body, the post-save redirect, and who is
 * listed as a participant. The Setting a new use opens with is the Uses
 * module's lastSetting(), falling back to the default Setting Preference.
 */

require_once __DIR__ . '/classes/Uses.php';

/**
 * How many identical uses one Record Use submit writes. Pages without the
 * Number of uses field post nothing and record one.
 */
function record_use_count(array $post): int
{
    $count = (int) ($post['useCount'] ?? 1);
    return max(1, min(Uses::MAX_COUNT, $count));
}

/** The Record Use form's POST in the Uses module's input names. */
function record_use_input(array $post): array
{
    return [
        'item_id' => $post['artifact']['id'] ?? '',
        'use_date' => $post['useDate'] ?? '',
        'setting' => $post['Note'] ?? '',
        'notes' => $post['NotesTwo'] ?? '',
        'player_ids' => array_column($post['user'] ?? [], 'id'),
        'count' => $post['useCount'] ?? 1,
    ];
}

function record_use_success_message(array $post): string
{
    $user_count = count($post['user'] ?? []);
    $user_count_word = $user_count === 1 ? 'person' : 'people';
    $use_count = record_use_count($post);
    $times = $use_count > 1 ? " $use_count times" : '';
    return 'The interaction with ' . ($post['artifact']['name'] ?? '')
        . " with $user_count $user_count_word was recorded$times.";
}

/** The quick-record JSON, with the item's use-by status from its Use-by queue entry. */
function record_use_ajax_payload(array $post, int $use_id, ?array $entry): array
{
    return [
        'ok' => true,
        'message' => record_use_success_message($post),
        'artifact_id' => (int) ($post['artifact']['id'] ?? 0),
        'artifact_name' => $post['artifact']['name'] ?? '',
        'artifact_type' => (string) ($entry['type'] ?? ''),
        'use_id' => $use_id,
        'use_date' => $post['useDate'] ?? '',
        'new_use_by_date' => $entry['use_by_date'] ?? null,
        'most_recent_use_date' => $entry['last_use'] ?? null,
        'is_overdue' => ($entry['status'] ?? null) === 'overdue',
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

/**
 * The group a use was recorded with: who was there, when, and where. Record
 * Use keeps it after a save so "Record another use with this group" can
 * reopen the form with only the item left to pick.
 */
function record_use_group(array $post, string $today): array
{
    $people = [];
    foreach ($post['user'] ?? [] as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id <= 0 || isset($people[$id])) {
            continue;
        }
        $people[$id] = ['id' => $id, 'name' => trim((string) ($person['name'] ?? ''))];
    }
    return [
        'people' => array_values($people),
        'useDate' => (string) ($post['useDate'] ?? ''),
        'Note' => (string) ($post['Note'] ?? ''),
        'savedOn' => $today,
    ];
}

/**
 * What the Record Use form opens with. Asked to record again ($again) with
 * a remembered group, it opens with that group's people, date, and setting
 * (the date only on the day the group was saved, so a later visit gets
 * $fallback's date); otherwise it opens with $fallback and offers the remembered group, if any,
 * as "Record another use with this group".
 */
function record_use_form(?array $last_group, bool $again, array $fallback): array
{
    $has_group = !empty($last_group['people']);
    if ($again && $has_group) {
        return [
            'people' => $last_group['people'],
            'useDate' => ($last_group['useDate'] ?? '') !== ''
                && ($last_group['savedOn'] ?? '') === $fallback['useDate']
                ? $last_group['useDate']
                : $fallback['useDate'],
            'Note' => (string) ($last_group['Note'] ?? ''),
            'offerGroup' => null,
        ];
    }
    return $fallback + ['offerGroup' => $has_group ? $last_group : null];
}
