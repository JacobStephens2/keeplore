<?php

/**
 * Item Interactions: the rows the Item Interactions page shows. The page
 * reads uses through Uses and, when item attributes are shown, items through
 * Items, then item_interactions() returns the rows, the number of uses it
 * was given, and the two combination counts. It does not query.
 *
 * group_and_setting_count is distinct group-and-setting keys.
 * group_setting_game_count is distinct keys of that text up through the
 * item title (the "Group, setting, and game combinations" line). Both
 * counts ignore a use hidden because its setting is exactly "online", and
 * both still count a repeated key once when that later use is shown.
 */

require_once __DIR__ . '/item_facts.php';

function item_interactions(array $uses, array $items, bool $hide_online, bool $hide_duplicate_group_settings): array
{
    $items_by_id = [];
    foreach ($items as $item) {
        $items_by_id[(int) ($item['id'] ?? 0)] = $item;
    }

    $group_and_setting_seen = [];
    $group_setting_game_seen = [];
    $rows = [];

    foreach ($uses as $use) {
        $setting = (string) ($use['setting'] ?? '');
        if ($hide_online && $setting === 'online') {
            continue;
        }

        $people = is_array($use['people'] ?? null) ? $use['people'] : [];
        $person_count = count($people);
        $names = [];
        foreach ($people as $person) {
            $names[$person['id']] = (string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? '');
        }
        ksort($names);

        $text = ($person_count < 10 ? '0' : '') . $person_count . ': ';
        $position = 0;
        foreach ($names as $name) {
            $position++;
            $text .= $name;
            // A comma follows unless this name's place equals the person-row
            // count. Duplicate ids shrink the name list, so the last name can
            // still take a comma; Candidate notes already match that spelling.
            if ($position !== $person_count) {
                $text .= ', ';
            }
        }
        if ($setting !== 'online') {
            $text .= ' at';
        }
        $text .= ' ' . $setting;

        $group_and_setting_seen[$text] = true;

        $title = (string) ($use['item_title'] ?? '');
        // The Candidate note is stored text, so the title in this key is the
        // title as stored, not its HTML spelling.
        $text .= ' (' . $title;
        $already_seen = isset($group_setting_game_seen[$text]);
        if (!$already_seen) {
            $group_setting_game_seen[$text] = true;
        } elseif ($hide_duplicate_group_settings) {
            continue;
        }
        $group_setting_game = $text;

        $date = substr((string) ($use['use_date'] ?? ''), 0, 10);
        $text .= ' on ' . $date . ')';

        $needle = mb_strtolower($group_setting_game);
        $candidate_spot = null;
        foreach ($items as $candidate_item) {
            $note = mb_strtolower((string) (item_facts_column($candidate_item, 'Candidate') ?? ''));
            if (str_starts_with($note, $needle)) {
                $candidate_spot = (string) ($candidate_item['Title'] ?? '');
                break;
            }
        }

        $own = $items_by_id[(int) ($use['item_id'] ?? 0)] ?? null;
        $rows[] = [
            'id' => (int) ($use['id'] ?? 0),
            'use_date' => $date,
            'item_id' => (int) ($use['item_id'] ?? 0),
            'item_title' => $title,
            'item_type' => (string) ($use['item_type'] ?? ''),
            'setting' => $setting,
            'person_count' => $person_count,
            'group_setting' => $text,
            'candidate' => $own !== null && item_is_candidate($own),
            'sweet_spot' => $own === null ? '' : (string) (item_facts_column($own, 'SS') ?? ''),
            'candidate_spot' => $candidate_spot,
        ];
    }

    return [
        'use_count' => count($uses),
        'group_and_setting_count' => count($group_and_setting_seen),
        'group_setting_game_count' => count($group_setting_game_seen),
        'rows' => $rows,
    ];
}
