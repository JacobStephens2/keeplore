<?php

/**
 * Items list page: filter state, display rows, and the JSON payload
 * /artifacts/ consumes. Callers do not query or shape rows themselves.
 */

require_once __DIR__ . '/kept_status.php';

function items_list_load_filter_defaults($user_id) {
    $default_interval = singleValueQuery(
        "SELECT default_use_interval FROM users WHERE id = " . (int) $user_id
    );
    global $typesArray;
    require_once SHARED_PATH . '/artifact_type_array.php';
    return [$default_interval, $typesArray ?? []];
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

    $sweetSpotFilter = $post['sweetSpotFilter'] ?? $get['sweetSpotFilter'] ?? '';
    $showAttributes = $post['showAttributes'] ?? $get['showAttributes'] ?? 'no';
    if ($showAttributes !== 'yes') {
        $showAttributes = 'no';
    }
    $tagFilter = $post['tag'] ?? $get['tag'] ?? '';

    return [
        'kept' => $kept,
        'type' => $type,
        'interval' => $interval,
        'sweetSpotFilter' => (string) $sweetSpotFilter,
        'showAttributes' => $showAttributes,
        'tagFilter' => (string) $tagFilter,
    ];
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
    if ($filters['sweetSpotFilter'] !== '') {
        $params['sweetSpotFilter'] = $filters['sweetSpotFilter'];
    }
    if ($filters['showAttributes'] === 'yes') {
        $params['showAttributes'] = 'yes';
    }
    if ($filters['tagFilter'] !== '') {
        $params['tag'] = $filters['tagFilter'];
    }
    return $params;
}

function items_list_present_row(array $artifact, $interval, $today = null) {
    $today = $today ?? date('Y-m-d');
    $max_play = $artifact['MaxPlay'] ?? null;
    $max_use = $artifact['MaxUse'] ?? null;
    if ($max_play === null && $max_use === null) {
        $most_recent_use = '';
    } elseif ($max_play > $max_use) {
        $most_recent_use = (string) $max_play;
    } else {
        $most_recent_use = (string) ($max_use ?? '');
    }

    if ($most_recent_use === '') {
        $conditional_interval = (int) floor($interval);
        $starting_date = $artifact['Acq'] ?? '';
    } else {
        $conditional_interval = (int) floor($interval * 2);
        $starting_date = $most_recent_use;
    }
    $timestamp = strtotime($starting_date . ' + ' . $conditional_interval . ' days');
    $use_by = ($timestamp === false) ? '1970-01-01' : date('Y-m-d', $timestamp);
    if ($use_by === '1970-01-01') {
        $use_by = '';
    }

    $is_kept = artifact_is_kept($artifact);
    $overdue = $use_by !== '' && $use_by < $today && $is_kept;

    $mnt = (float) ($artifact['mnt'] ?? $artifact['MnT'] ?? 0);
    $mxt = (float) ($artifact['mxt'] ?? $artifact['MxT'] ?? 0);
    $candidate_raw = $artifact['Candidate'] ?? '';

    return [
        'id' => (int) ($artifact['id'] ?? 0),
        'title' => (string) ($artifact['Title'] ?? ''),
        'type' => (string) ($artifact['type'] ?? ''),
        'tags' => $artifact['tags'] ?? [],
        'is_kept' => $is_kept,
        'acq' => (string) ($artifact['Acq'] ?? ''),
        'most_recent_use' => $most_recent_use,
        'use_by' => $use_by,
        'use_by_overdue' => $overdue,
        'ss' => (string) ($artifact['ss'] ?? $artifact['SS'] ?? ''),
        'avg_time' => (int) ceil(($mnt + $mxt) / 2),
        'candidate' => ($candidate_raw != '' && $candidate_raw != 0),
    ];
}

function items_list_payload($db, array $filters, $user_id, $today = null) {
    $artifact_set = find_artifacts_by_user_id(
        $filters['kept'],
        $filters['type'],
        $filters['interval'],
        $filters['sweetSpotFilter'],
        $filters['tagFilter']
    );
    $artifacts = [];
    while ($row = mysqli_fetch_assoc($artifact_set)) {
        $artifacts[] = $row;
    }
    mysqli_free_result($artifact_set);
    $artifacts = with_item_tags($db, $artifacts, (int) $user_id);
    $items = [];
    foreach ($artifacts as $artifact) {
        $items[] = items_list_present_row($artifact, $filters['interval'], $today);
    }
    return $items;
}
