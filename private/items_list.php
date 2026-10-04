<?php

/**
 * Items list page: filter state, display rows, and the JSON payload
 * /artifacts/ consumes. Callers do not query or shape rows themselves.
 */

require_once __DIR__ . '/kept_status.php';
require_once __DIR__ . '/bgg_ratings.php';
require_once __DIR__ . '/item_types.php';
require_once __DIR__ . '/use_by_date.php';
require_once __DIR__ . '/classes/Items.php';

function items_list_load_filter_defaults($user_id) {
    global $db;
    $default_interval = default_use_interval($db, $user_id);
    return [$default_interval, array_column((new Types($db, (int) $user_id))->all(), 'id', 'name')];
}

function items_list_filters_from_request(
    array $get,
    array $post,
    string $method,
    $default_interval,
    array $all_types
) {
    $is_post = strtoupper($method) === 'POST';
    $source = $is_post ? $post : $get;

    if ($is_post) {
        $kept = $post['kept'] ?? 'allkeptandnot';
        if (isset($post['type'])) {
            if ($post['type'] == '1') {
                $type = [''];
            } else {
                $type = $post['type'];
            }
        } else {
            $type = [];
        }
    } else {
        if (isset($get['kept'])) {
            $kept = $get['kept'];
        } else {
            $kept = 'allkeptandnot';
        }
        if (isset($get['type'])) {
            $type = [];
            foreach ((array) $get['type'] as $selected_type) {
                if ($selected_type !== '' && $selected_type !== null) {
                    $type[] = $selected_type;
                }
            }
        } else {
            $type = $all_types;
        }
    }

    $kept_aliases = [
        'all' => 'allkeptandnot',
        'allkeptandnot' => 'allkeptandnot',
        'yes' => 'yes',
        'no' => 'no',
        'secondary_only' => 'secondary_only',
    ];
    $kept = $kept_aliases[$kept] ?? 'allkeptandnot';

    $interval = $post['interval'] ?? $get['interval'] ?? $default_interval;
    if (!is_numeric($interval)) {
        $interval = $default_interval;
    } else {
        $interval = 0 + $interval;
    }

    // sweetSpotFilter is the old name for the count, kept so bookmarks still work.
    $players = $post['players'] ?? $get['players']
        ?? $post['sweetSpotFilter'] ?? $get['sweetSpotFilter'] ?? '';
    $players = items_list_positive_int($players);
    // The youngest player's age: items must be recommended for this age or younger.
    $age = items_list_positive_int($post['age'] ?? $get['age'] ?? '');
    // Whether the age filter also keeps items with no recorded minimum age.
    $age_unknown = ($post['age_unknown'] ?? $get['age_unknown'] ?? 'no') === 'yes';
    $showAttributes = $post['showAttributes'] ?? $get['showAttributes'] ?? 'no';
    if ($showAttributes !== 'yes') {
        $showAttributes = 'no';
    }
    $tagFilter = $post['tag'] ?? $get['tag'] ?? '';

    return [
        'kept' => $kept,
        'type' => $type,
        'interval' => $interval,
        'players' => $players,
        'age' => $age,
        'ageUnknown' => $age_unknown,
        'showAttributes' => $showAttributes,
        'tagFilter' => (string) $tagFilter,
    ];
}

/**
 * The Items type switch: All types, Games and Other, each with the type ids
 * it selects, and which one the current selection matches (null for a
 * hand-picked mix). A choice the user has no types for is left out; an empty
 * selection means every type.
 */
function items_list_type_switch(array $all_types, array $current_type_ids) {
    $all_ids = array_map('strval', array_values($all_types));
    $other_ids = item_other_type_ids($all_types);
    $options = ['all' => ['label' => 'All types', 'type_ids' => $all_ids]];
    $game_ids = item_game_type_ids($all_types);
    if ($game_ids !== []) {
        $options['games'] = ['label' => 'Games', 'type_ids' => $game_ids];
    }
    if ($other_ids !== []) {
        $options['other'] = ['label' => 'Other', 'type_ids' => $other_ids];
    }

    $current = [];
    foreach ($current_type_ids as $id) {
        if ($id !== '' && $id !== null) {
            $current[] = (string) $id;
        }
    }
    $current = $current === [] ? $all_ids : $current;
    sort($current);
    $active = null;
    foreach ($options as $key => $option) {
        $ids = $option['type_ids'];
        sort($ids);
        if ($ids === $current) {
            $active = $key;
            break;
        }
    }
    return ['options' => $options, 'active' => $active];
}

/**
 * The Items table's columns in order, each ['key' (its sort key and cell
 * kind), 'label', 'tooltip' (or '')]. The page draws its headings and
 * items-list.js its cells from this one list. Recent Interaction sits left of
 * Type and Tracking Start so it shows without scrolling. A players or age
 * search, or item attributes, adds Age, the player range with its best
 * count, and the play time.
 */
function items_list_columns(array $filters, array $bgg_reviewers) {
    $attributes = $filters['showAttributes'] === 'yes';
    $columns = [
        ['key' => 'is_kept', 'label' => 'Kept', 'tooltip' => ''],
        ['key' => 'title', 'label' => 'Name', 'tooltip' => ''],
    ];
    if ($filters['age'] !== null || $attributes) {
        $columns[] = ['key' => 'age', 'label' => 'Age', 'tooltip' => 'Recommended minimum age'];
    }
    if ($filters['players'] !== null || $filters['age'] !== null || $attributes) {
        $columns[] = ['key' => 'players', 'label' => 'Players', 'tooltip' => 'Player range, with the best count in brackets'];
        $columns[] = ['key' => 'time', 'label' => 'Time', 'tooltip' => 'Play time in minutes'];
    }
    $columns[] = [
        'key' => 'bgg_average',
        'label' => 'BGG',
        'tooltip' => 'BoardGameGeek average rating',
    ];
    foreach ($bgg_reviewers as $reviewer) {
        $columns[] = [
            'key' => 'bgg_rating:' . $reviewer,
            'label' => $reviewer,
            'tooltip' => $reviewer . "'s BoardGameGeek rating. Select one to read the comment.",
        ];
    }
    $columns[] = ['key' => 'tags', 'label' => 'Tags', 'tooltip' => ''];
    $columns[] = ['key' => 'most_recent_use', 'label' => 'Recent Interaction', 'tooltip' => ''];
    $columns[] = ['key' => 'type', 'label' => 'Type', 'tooltip' => ''];
    $columns[] = ['key' => 'acq', 'label' => 'Tracking Start', 'tooltip' => ''];
    $columns[] = ['key' => 'use_by', 'label' => 'Interact By', 'tooltip' => ''];
    if ($attributes) {
        $columns[] = ['key' => 'avg_time', 'label' => 'AvgT', 'tooltip' => 'Average play time in minutes'];
        $columns[] = ['key' => 'candidate', 'label' => 'Candidate', 'tooltip' => ''];
    }
    return $columns;
}

/** A whole number of 1 or more typed into a filter, or null for anything else. */
function items_list_positive_int($value) {
    return is_string($value) && preg_match('/^\s*[1-9]\d*\s*$/', $value) ? (int) $value : null;
}

function items_list_query_params(array $filters, array $all_types = []) {
    $params = [
        'kept' => $filters['kept'],
        'interval' => $filters['interval'],
    ];
    $type_ids = [];
    if (isset($filters['type']) && is_array($filters['type'])) {
        foreach (array_values($filters['type']) as $type_id) {
            if ($type_id !== '' && $type_id !== null) {
                $type_ids[] = (string) $type_id;
            }
        }
    }
    $all_type_ids = array_map('strval', array_values($all_types));
    sort($type_ids);
    sort($all_type_ids);
    if ($type_ids !== [] && $type_ids !== $all_type_ids) {
        $params['type'] = $type_ids;
    }
    if ($filters['players'] !== null) {
        $params['players'] = $filters['players'];
    }
    if ($filters['age'] !== null) {
        $params['age'] = $filters['age'];
        if ($filters['ageUnknown']) {
            $params['age_unknown'] = 'yes';
        }
    }
    if ($filters['showAttributes'] === 'yes') {
        $params['showAttributes'] = 'yes';
    }
    if ($filters['tagFilter'] !== '') {
        $params['tag'] = $filters['tagFilter'];
    }
    return $params;
}

/**
 * One Items row for display. $artifact is a row from Items::list: its
 * last_use is Recent Interaction and its type_name the Type.
 */
function items_list_present_row(array $artifact, $default_interval, $today = null) {
    $today = $today ?? date('Y-m-d');
    $last_use = (string) ($artifact['last_use'] ?? '');

    // The view's interval is a default only; the item's own frequency wins.
    $use_by_date = use_by_date(
        $artifact['Acq'] ?? null,
        $last_use,
        $artifact['interaction_frequency_days'] ?? null,
        $default_interval
    ) ?? '';

    $is_kept = artifact_is_kept($artifact);
    $overdue = $use_by_date !== '' && $use_by_date < $today && $is_kept;

    $mnt = (float) ($artifact['mnt'] ?? $artifact['MnT'] ?? 0);
    $mxt = (float) ($artifact['mxt'] ?? $artifact['MxT'] ?? 0);
    $candidate_raw = $artifact['Candidate'] ?? '';
    $min_age = items_list_min_age($artifact);

    return [
        'id' => (int) ($artifact['id'] ?? 0),
        'title' => (string) ($artifact['Title'] ?? ''),
        'type' => (string) ($artifact['type_name'] ?? ''),
        'tags' => $artifact['tags'] ?? [],
        'is_kept' => $is_kept,
        'acq' => (string) ($artifact['Acq'] ?? ''),
        'most_recent_use' => $last_use,
        'use_by' => $use_by_date,
        'use_by_overdue' => $overdue,
        'ss' => (string) ($artifact['ss'] ?? $artifact['SS'] ?? ''),
        'players' => items_list_players_label(
            $artifact['mnp'] ?? $artifact['MnP'] ?? null,
            $artifact['mxp'] ?? $artifact['MxP'] ?? null,
            $artifact['ss'] ?? $artifact['SS'] ?? null
        ),
        'age' => $min_age === null ? '' : $min_age . '+',
        'copy_text' => items_list_copy_text($artifact),
        'time' => items_list_play_time($artifact['mnt'] ?? $artifact['MnT'] ?? null, $artifact['mxt'] ?? $artifact['MxT'] ?? null),
        'avg_time' => (int) ceil(($mnt + $mxt) / 2),
        'candidate' => ($candidate_raw != '' && $candidate_raw != 0),
        'bgg_average' => bgg_overall_rating_text($artifact['BGG_Rat'] ?? $artifact['bgg_rat'] ?? null) ?? '',
        // Keyed by BGG username; an object even when empty so the JSON is {}.
        'bgg_ratings' => empty($artifact['bgg_ratings']) ? new stdClass() : $artifact['bgg_ratings'],
    ];
}

/**
 * The Items page's rows: the owner's Items from Items::list, narrowed by the
 * page's players and youngest-age filters, newest acquisition first, then
 * kept first, then id.
 */
function items_list_payload($db, array $filters, $user_id, $today = null) {
    $artifacts = (new Items($db, (int) $user_id))->list(items_list_item_filters($filters));
    usort($artifacts, fn (array $a, array $b) =>
        [(string) ($b['Acq'] ?? ''), artifact_is_kept($b), (int) $a['id']]
        <=> [(string) ($a['Acq'] ?? ''), artifact_is_kept($a), (int) $b['id']]);
    $artifacts = items_list_best_at($artifacts, $filters['players']);
    $artifacts = items_list_suitable_for_age($artifacts, $filters['age'], $filters['ageUnknown']);
    $artifacts = with_item_bgg_ratings($db, $artifacts, (int) $user_id);
    $items = [];
    foreach ($artifacts as $artifact) {
        $items[] = items_list_present_row($artifact, $filters['interval'], $today);
    }
    return $items;
}

/**
 * Items::list's filters for the page's kept, type and tag choices. An empty
 * type list means every type; blank type entries are dropped, so a list of
 * only blanks lists nothing.
 */
function items_list_item_filters(array $filters) {
    $item_filters = match ($filters['kept']) {
        'yes' => ['kept' => true],
        'no' => ['kept' => false],
        'secondary_only' => ['secondary_collection' => true],
        default => [],
    };
    $type = (array) ($filters['type'] ?? []);
    if ($type !== []) {
        $item_filters['type_ids'] = array_values(array_filter($type, fn ($id) => $id !== '' && $id !== null));
    }
    $item_filters['tag'] = $filters['tagFilter'];
    return $item_filters;
}

/**
 * The player counts a sweet spot names, ascending. Stored sweet spots come in
 * every spelling the field has had: BGG's zero-padded "03,04", hand-typed
 * "3, 4", and ranges such as "06-8".
 */
function items_list_sweet_spot_counts($ss) {
    $counts = [];
    // Close up "3 - 6" to "3-6" first, so the split below keeps the range whole.
    $ss = preg_replace('/\s*([-–])\s*/u', '$1', trim((string) $ss));
    foreach (preg_split('/[,\s]+/', $ss, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $part, $range)) {
            $min = (int) $range[1];
            $max = (int) $range[2];
        } elseif (preg_match('/^\d+$/', $part)) {
            $min = $max = (int) $part;
        } else {
            continue;
        }
        // A mistyped range such as "1-2000000000" must not stall the list.
        for ($n = max(1, $min); $n <= min($max, 99); $n++) {
            $counts[$n] = true;
        }
    }
    ksort($counts);
    return array_keys($counts);
}

/**
 * The line Items' Copy button puts on the clipboard for sharing a game:
 * "Azul, 2–4 (2), 8 yrs", the name, the player range with its best counts,
 * and the minimum age. A part with nothing recorded is left out.
 */
function items_list_copy_text(array $artifact) {
    $parts = [(string) ($artifact['Title'] ?? '')];
    $range = items_list_player_range($artifact['mnp'] ?? $artifact['MnP'] ?? null, $artifact['mxp'] ?? $artifact['MxP'] ?? null);
    $best = items_list_best_counts_label($artifact['ss'] ?? $artifact['SS'] ?? '');
    if ($range !== '') {
        $parts[] = $best === '' ? $range : $range . ' (' . $best . ')';
    } elseif ($best !== '') {
        $parts[] = 'best ' . $best;
    }
    $min_age = items_list_min_age($artifact);
    if ($min_age !== null) {
        $parts[] = $min_age . ' yrs';
    }
    return implode(', ', $parts);
}

/**
 * The line under Edit Item's heading, so the facts people look up most sit
 * above the fold even in a half-width window: "2–4 players, best 3 ·
 * 30–60 min · Age 8+".
 * A part with nothing recorded is left out; '' when nothing is.
 */
function items_list_play_facts(array $artifact) {
    $min = $artifact['MnP'] ?? $artifact['mnp'] ?? null;
    $max = $artifact['MxP'] ?? $artifact['mxp'] ?? null;
    $range = items_list_player_range($min, $max);
    $best = items_list_best_counts_label($artifact['SS'] ?? $artifact['ss'] ?? '');
    $parts = [];
    if ($range !== '') {
        $players = $range . ($range === '1' ? ' player' : ' players');
        $parts[] = $best === '' ? $players : $players . ', best ' . $best;
    } elseif ($best !== '') {
        $parts[] = 'Best at ' . $best;
    }
    $time = items_list_play_time($artifact['MnT'] ?? $artifact['mnt'] ?? null, $artifact['MxT'] ?? $artifact['mxt'] ?? null);
    if ($time !== '') {
        $parts[] = $time;
    }
    $min_age = items_list_min_age($artifact);
    if ($min_age !== null) {
        $parts[] = 'Age ' . $min_age . '+';
    }
    return implode(' · ', $parts);
}

/** The player range, as in "2–4", "3" or '' when none is recorded. */
function items_list_player_range($min, $max) {
    $min = (int) $min;
    $max = (int) $max;
    if ($min <= 0 && $max <= 0) {
        return '';
    }
    if ($min <= 0 || $max <= 0 || $min === $max) {
        return (string) max($min, $max);
    }
    return $min . '–' . $max;
}

/** The play time, as in "30–60 min", "45 min", or '' when none is recorded. */
function items_list_play_time($min, $max) {
    // A time range reads like a player range: "30–60", or "45" with one end.
    $range = items_list_player_range($min, $max);
    return $range === '' ? '' : $range . ' min';
}

/**
 * The sweet spot's counts, as in "3" or "3, 4". A run of three or more
 * consecutive counts reads as a range, so "3–5" but "3, 4".
 */
function items_list_best_counts_label($ss) {
    $runs = [];
    foreach (items_list_sweet_spot_counts($ss) as $n) {
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last][1] === $n - 1) {
            $runs[$last][1] = $n;
        } else {
            $runs[] = [$n, $n];
        }
    }
    return implode(', ', array_map(function ($run) {
        if ($run[1] - $run[0] >= 2) {
            return $run[0] . '–' . $run[1];
        }
        return implode(', ', range($run[0], $run[1]));
    }, $runs));
}

/** The player range with the sweet spot, as in "2–4 (best 3)". */
function items_list_players_label($min, $max, $ss) {
    $range = items_list_player_range($min, $max);
    $best = items_list_best_counts_label($ss);
    if ($best === '') {
        return $range;
    }
    return $range === '' ? 'best ' . $best : $range . ' (best ' . $best . ')';
}

/**
 * The title for a chosen count and youngest age, as in "Best at 3 players,
 * suitable for age 2", or null with neither.
 */
function items_list_heading($players, $age) {
    $parts = [];
    if ($players !== null) {
        $parts[] = 'Best at ' . $players . ($players === 1 ? ' player' : ' players');
    }
    if ($age !== null) {
        $parts[] = ($parts ? 'suitable' : 'Suitable') . ' for age ' . $age;
    }
    return $parts ? implode(', ', $parts) : null;
}

/** An item's recommended minimum age, or null when none is recorded. */
function items_list_min_age(array $row) {
    $age = (int) ($row['Age'] ?? $row['age'] ?? 0);
    return $age > 0 ? $age : null;
}

/**
 * The rows recommended for the age or younger, or every row with no age. An
 * item with no recorded minimum age is left out, since nothing vouches for it,
 * unless the caller asks to include unknown ages.
 */
function items_list_suitable_for_age(array $rows, $age, $include_unknown = false) {
    if ($age === null) {
        return $rows;
    }
    return array_values(array_filter($rows, function ($row) use ($age, $include_unknown) {
        $min_age = items_list_min_age($row);
        return $min_age === null ? $include_unknown : $min_age <= $age;
    }));
}

/** The rows whose sweet spot includes the count, or every row with no count. */
function items_list_best_at(array $rows, $players) {
    if ($players === null) {
        return $rows;
    }
    return array_values(array_filter($rows, function ($row) use ($players) {
        return in_array($players, items_list_sweet_spot_counts($row['ss'] ?? $row['SS'] ?? ''), true);
    }));
}
