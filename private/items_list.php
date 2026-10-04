<?php

/**
 * Items list page: filter state, display rows, and the JSON payload
 * /artifacts/ consumes. Callers do not query or shape rows themselves.
 */

require_once __DIR__ . '/kept_status.php';
require_once __DIR__ . '/item_types.php';
require_once __DIR__ . '/item_facts.php';
require_once __DIR__ . '/use_by_date.php';
require_once __DIR__ . '/app_day.php';
require_once __DIR__ . '/classes/Items.php';
require_once __DIR__ . '/classes/BggRatings.php';
require_once __DIR__ . '/classes/Preferences.php';

function items_list_load_filter_defaults($user_id) {
    global $db;
    $default_interval = (new Preferences($db, (int) $user_id))->get()['default_use_interval'];
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

    $interval = use_by_view_interval($post['interval'] ?? $get['interval'] ?? $default_interval, $default_interval);

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

    $current = items_list_type_ids($current_type_ids);
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

/**
 * The changes the filter panel makes: it shows kept, Type, tag and item
 * attributes, so it sets them itself and carries every other filter.
 */
const ITEMS_LIST_FILTER_PANEL_CHANGES = [
    'kept' => null,
    'type' => null,
    'tagFilter' => null,
    'showAttributes' => null,
];

/**
 * The Items page's GET query for the filters with $changes applied, keyed by
 * filter name, where null puts a filter back to its default. A default is
 * left out, since the request parser reads its absence as the default: kept
 * for all items, a Type list that is empty or every Type, the default
 * interval, no players or age, attributes off and a blank tag. Every link,
 * carried field and the list's data URL on the page comes from this.
 */
function items_list_filter_query(array $filters, array $all_types, $default_interval, array $changes = []) {
    $filters = array_replace($filters, $changes);
    $query = [];

    $kept = $filters['kept'] ?? 'allkeptandnot';
    if (in_array($kept, ['yes', 'no', 'secondary_only'], true)) {
        $query['kept'] = $kept;
    }

    $type_ids = items_list_type_ids($filters['type'] ?? []);
    $sorted_ids = $type_ids;
    $all_type_ids = array_map('strval', array_values($all_types));
    sort($sorted_ids);
    sort($all_type_ids);
    if ($type_ids !== [] && $sorted_ids !== $all_type_ids) {
        $query['type'] = $type_ids;
    }

    $interval = $filters['interval'] ?? $default_interval;
    if ((string) $interval !== (string) $default_interval) {
        $query['interval'] = $interval;
    }
    if (($filters['players'] ?? null) !== null) {
        $query['players'] = $filters['players'];
    }
    if (($filters['age'] ?? null) !== null) {
        $query['age'] = $filters['age'];
        if ($filters['ageUnknown'] ?? false) {
            $query['age_unknown'] = 'yes';
        }
    }
    if (($filters['showAttributes'] ?? 'no') === 'yes') {
        $query['showAttributes'] = 'yes';
    }
    $tag = (string) ($filters['tagFilter'] ?? '');
    if (trim($tag) !== '') {
        $query['tag'] = $tag;
    }
    return $query;
}

/**
 * A query as hidden-field [name, value] pairs, so a form carries it: a list
 * becomes type[0], type[1] and so on.
 */
function items_list_hidden_fields(array $query) {
    $flatten = function (array $values, $prefix) use (&$flatten) {
        $fields = [];
        foreach ($values as $key => $value) {
            $name = $prefix === null ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                array_push($fields, ...$flatten($value, $name));
            } else {
                $fields[] = [$name, (string) $value];
            }
        }
        return $fields;
    };
    return $flatten($query, null);
}

/** The non-blank ids in a Type selection, as strings in their given order. */
function items_list_type_ids($type) {
    $ids = [];
    foreach ((array) $type as $id) {
        if ($id !== '' && $id !== null) {
            $ids[] = (string) $id;
        }
    }
    return $ids;
}

/**
 * One Items row for display. $artifact is a row from Items::list: its
 * last_use is Recent Interaction and its type_name the Type. $today is a
 * Y-m-d day, the app's day when omitted.
 */
function items_list_present_row(array $artifact, $default_interval, $today = null) {
    $last_use = (string) ($artifact['last_use'] ?? '');

    // The view's interval is a default only; the item's own frequency wins.
    $use_by = use_by_status($artifact, $default_interval, $today ?? app_today());

    // Only a kept item is overdue here.
    $is_kept = artifact_is_kept($artifact);
    $overdue = $is_kept && $use_by['status'] === 'overdue';

    $min_age = item_min_age($artifact);

    return [
        'id' => (int) ($artifact['id'] ?? 0),
        'title' => (string) ($artifact['Title'] ?? ''),
        'type' => (string) ($artifact['type_name'] ?? ''),
        'tags' => $artifact['tags'] ?? [],
        'is_kept' => $is_kept,
        'acq' => (string) ($artifact['Acq'] ?? ''),
        'most_recent_use' => $last_use,
        'use_by' => $use_by['use_by_date'] ?? '',
        'use_by_overdue' => $overdue,
        'ss' => (string) ($artifact['ss'] ?? $artifact['SS'] ?? ''),
        'players' => item_players_label($artifact),
        'age' => $min_age === null ? '' : $min_age . '+',
        'copy_text' => item_copy_text($artifact),
        'time' => item_play_time($artifact),
        'avg_time' => item_average_play_time($artifact),
        'candidate' => item_is_candidate($artifact),
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
    if ($filters['players'] !== null) {
        $artifacts = array_filter($artifacts, fn (array $item) => item_plays_best_at($item, $filters['players']));
    }
    if ($filters['age'] !== null) {
        $artifacts = array_filter($artifacts, fn (array $item) => item_suits_age($item, $filters['age'], $filters['ageUnknown']));
    }
    $bgg_ratings = (new BggRatings($db, (int) $user_id))->forItems(array_column($artifacts, 'id'));
    $items = [];
    foreach ($artifacts as $artifact) {
        $artifact['bgg_ratings'] = $bgg_ratings[(int) $artifact['id']] ?? [];
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
        $item_filters['type_ids'] = items_list_type_ids($type);
    }
    $item_filters['tag'] = $filters['tagFilter'];
    return $item_filters;
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
