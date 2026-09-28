<?php

/**
 * An event's games grouped for planning: by sweet spot, age, setting or tag,
 * then optionally by a second of those, and written out as a packing
 * checklist. A game belongs to every group its values name, so one best at
 * 6 and 8 shows under both "8 players" and "6 players".
 *
 * Items are rows with Title, MnP, MxP, SS, Age, MnT, MxT, tags, is_kept, and the
 * event's own setting, note and is_packed.
 */

require_once __DIR__ . '/items_list.php';

/** The ways an event's games can be grouped, keyed by the value forms send. */
function event_plan_dimensions() {
    return [
        'none' => 'Nothing',
        'players' => 'Sweet spot',
        'age' => 'Age',
        'setting' => 'Setting',
        'tag' => 'Tag',
    ];
}

/**
 * The items grouped by $by, each group's items grouped again by $then. Each
 * group is ['label' => ..., 'items' => [...], 'groups' => [...]], where
 * 'groups' is empty without a second grouping. Unknown dimensions, and $then
 * repeating $by, mean no grouping at that level. Items with nothing to group
 * by collect in a last group such as "No sweet spot"; within a group they
 * come first and unlabelled, the way a hand-written list leaves them.
 *
 * $tags, when given, are the only tags that make groups, in that order, so
 * "casual, main" splits each player count in two and ignores "beach-safe".
 */
function event_plan_groups(array $items, $by, $then = 'none', array $tags = []) {
    $by = isset(event_plan_dimensions()[$by]) ? $by : 'none';
    $then = isset(event_plan_dimensions()[$then]) && $then !== $by ? $then : 'none';
    usort($items, function ($a, $b) {
        return strcasecmp((string) $a['Title'], (string) $b['Title']) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
    });

    $groups = [];
    $tags = event_plan_chosen_tags($tags);
    foreach (event_plan_split($items, $by, $tags, false) as $group) {
        $group['groups'] = $then === 'none' ? [] : array_map(function ($sub) {
            return $sub + ['groups' => []];
        }, event_plan_split($group['items'], $then, $tags, true));
        $groups[] = $group;
    }
    return $groups;
}

/**
 * The grouping a request asks for (by, then, tags), each part falling back to
 * the saved one and then to "Sweet spot, nothing, no tags". Naming tags with
 * no second grouping sub-groups by tag, since the tags would otherwise do
 * nothing.
 */
function event_plan_grouping(array $request, array $saved) {
    $saved += ['by' => 'players', 'then' => 'none', 'tags' => ''];
    $dimensions = event_plan_dimensions();
    $pick = function ($key) use ($request, $saved, $dimensions) {
        $value = $request[$key] ?? null;
        if (is_string($value) && isset($dimensions[$value])) {
            return $value;
        }
        return isset($dimensions[$saved[$key]]) ? $saved[$key] : ($key === 'by' ? 'players' : 'none');
    };
    $by = $pick('by');
    $then = $pick('then');
    $tags = is_string($request['tags'] ?? null) ? $request['tags'] : (string) $saved['tags'];
    $tags = implode(', ', event_plan_chosen_tags($tags));
    if ($tags !== '' && $by !== 'tag' && $then === 'none') {
        $then = 'tag';
    }
    return ['by' => $by, 'then' => $then, 'tags' => $tags];
}

/** An event's dates as "Jul 3 – Jul 10, 2027", or '' with neither. */
function event_dates_label($starts_on, $ends_on) {
    $format = function ($date, $with_year = true) {
        return date($with_year ? 'M j, Y' : 'M j', strtotime($date));
    };
    if (!$starts_on) {
        return $ends_on ? 'Until ' . $format($ends_on) : '';
    }
    if (!$ends_on || $ends_on === $starts_on) {
        return $format($starts_on);
    }
    $same_year = substr($starts_on, 0, 4) === substr($ends_on, 0, 4);
    return $format($starts_on, !$same_year) . ' – ' . $format($ends_on);
}

/** The items as a checklist: "# group", "## sub-group", "- [ ] line". */
function event_plan_text(array $groups) {
    $blocks = [];
    foreach ($groups as $group) {
        $lines = [];
        if ($group['label'] !== '') {
            $lines[] = '# ' . $group['label'];
        }
        if ($group['groups'] === []) {
            $lines = array_merge($lines, event_plan_checklist($group['items']));
        }
        foreach ($group['groups'] as $sub) {
            if ($sub['label'] !== '') {
                $lines[] = '## ' . $sub['label'];
            }
            $lines = array_merge($lines, event_plan_checklist($sub['items']));
        }
        $blocks[] = implode("\n", $lines) . "\n";
    }
    return implode("\n", $blocks);
}

/**
 * One game as the checklist names it: "Hanabi, 2–5 (4), 10 yrs, 25 min,
 * beach, requested by mom", the Items copy line plus the play time, the
 * event's setting and note,
 * and "not kept" for a game planned before it is bought.
 */
function event_plan_line(array $item) {
    return (string) ($item['Title'] ?? '') . event_plan_details($item);
}

/**
 * The checklist line after the title, as in ", 2–5 (4), 10 yrs, beach", or
 * '' with nothing recorded, so a page can link the title on its own.
 */
function event_plan_details(array $item) {
    $title = (string) ($item['Title'] ?? '');
    // items_list_copy_text() leads with the title; keep what follows it.
    $parts = [substr(items_list_copy_text($item), strlen($title))];
    $time = items_list_play_time($item['MnT'] ?? $item['mnt'] ?? null, $item['MxT'] ?? $item['mxt'] ?? null);
    if ($time !== '') {
        $parts[] = ', ' . $time;
    }
    foreach (['setting', 'note'] as $field) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '') {
            $parts[] = ', ' . $value;
        }
    }
    // Only a row that says it is not kept is marked; rows without the field are left alone.
    if (array_key_exists('is_kept', $item) && !$item['is_kept']) {
        $parts[] = ', not kept';
    }
    return implode('', $parts);
}

function event_plan_checklist(array $items) {
    return array_map(function ($item) {
        return '- [' . (empty($item['is_packed']) ? ' ' : 'x') . '] ' . event_plan_line($item);
    }, $items);
}

/** Chosen tags, as typed ("casual, main") or a list, normalized and in order. */
function event_plan_chosen_tags($tags) {
    $chosen = [];
    foreach (is_array($tags) ? $tags : explode(',', (string) $tags) as $tag) {
        $tag = mb_strtolower(trim((string) $tag));
        if ($tag !== '' && !in_array($tag, $chosen, true)) {
            $chosen[] = $tag;
        }
    }
    return $chosen;
}

/**
 * Items (already in title order) split into ordered groups by one
 * dimension. Items lacking a value form a last, labelled group, or with
 * $is_sub a first, unlabelled one.
 */
function event_plan_split(array $items, $by, array $tags, $is_sub) {
    if ($by === 'none') {
        return [['label' => '', 'items' => $items]];
    }
    $groups = [];
    $missing = [];
    foreach ($items as $item) {
        $keys = event_plan_keys($item, $by, $tags);
        if ($keys === []) {
            $missing[] = $item;
        }
        foreach ($keys as $sort => $label) {
            if (!isset($groups[$sort])) {
                $groups[$sort] = ['label' => $label, 'items' => []];
            }
            $groups[$sort]['items'][] = $item;
        }
    }
    if ($by === 'players') {
        krsort($groups, SORT_NUMERIC);
    } elseif ($by === 'age') {
        ksort($groups, SORT_NUMERIC);
    } else {
        ksort($groups, SORT_STRING);
    }
    $groups = array_values($groups);
    if ($missing !== [] && $is_sub) {
        array_unshift($groups, ['label' => '', 'items' => $missing]);
    } elseif ($missing !== []) {
        $none = ['players' => 'No sweet spot', 'age' => 'No age recorded', 'setting' => 'No setting', 'tag' => 'Untagged'];
        $groups[] = ['label' => $none[$by], 'items' => $missing];
    }
    return $groups;
}

/** The groups one item belongs to, as sort key => label. */
function event_plan_keys(array $item, $by, array $tags) {
    if ($by === 'players') {
        $keys = [];
        foreach (items_list_sweet_spot_counts($item['SS'] ?? $item['ss'] ?? '') as $n) {
            $keys[$n] = $n . ($n === 1 ? ' player' : ' players');
        }
        return $keys;
    }
    if ($by === 'age') {
        $age = items_list_min_age($item);
        return $age === null ? [] : [$age => 'Age ' . $age . '+'];
    }
    $values = $by === 'tag' ? (array) ($item['tags'] ?? []) : [$item['setting'] ?? ''];
    $keys = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        // A prefix keeps numeric-looking values string keys, so ksort stays alphabetical.
        $key = 'k' . mb_strtolower($value);
        if ($by === 'tag' && $tags !== []) {
            $position = array_search(mb_strtolower($value), $tags, true);
            if ($position === false) {
                continue;
            }
            // Zero-padded, so ksort keeps the chosen order.
            $key = sprintf('k%04d', $position);
        }
        if ($value !== '' && !isset($keys[$key])) {
            $keys[$key] = $value;
        }
    }
    return $keys;
}
