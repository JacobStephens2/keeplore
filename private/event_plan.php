<?php

/**
 * An event's games grouped for planning: by sweet spot, age, setting or tag,
 * then optionally by a second of those, and written out as a packing
 * checklist. A game belongs to every group its values name, so one best at
 * 6 and 8 shows under both "8 players" and "6 players".
 *
 * Items are rows with Title, MnP, MxP, SS, Age, tags, and the event's own
 * setting, note and is_packed.
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
 * by collect in a last group such as "No sweet spot".
 */
function event_plan_groups(array $items, $by, $then = 'none') {
    $by = isset(event_plan_dimensions()[$by]) ? $by : 'none';
    $then = isset(event_plan_dimensions()[$then]) && $then !== $by ? $then : 'none';
    usort($items, function ($a, $b) {
        return strcasecmp((string) $a['Title'], (string) $b['Title']) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
    });

    $groups = [];
    foreach (event_plan_split($items, $by) as $group) {
        $group['groups'] = $then === 'none' ? [] : array_map(function ($sub) {
            return $sub + ['groups' => []];
        }, event_plan_split($group['items'], $then));
        $groups[] = $group;
    }
    return $groups;
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
 * One game as the checklist names it: "Hanabi, 2–5 (4), 10 yrs, beach,
 * requested by mom", the Items copy line plus the event's setting and note.
 */
function event_plan_line(array $item) {
    $parts = [items_list_copy_text($item)];
    foreach (['setting', 'note'] as $field) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    return implode(', ', $parts);
}

function event_plan_checklist(array $items) {
    return array_map(function ($item) {
        return '- [' . (empty($item['is_packed']) ? ' ' : 'x') . '] ' . event_plan_line($item);
    }, $items);
}

/**
 * Items (already in title order) split into ordered groups by one
 * dimension, with the group for items lacking a value last.
 */
function event_plan_split(array $items, $by) {
    if ($by === 'none') {
        return [['label' => '', 'items' => $items]];
    }
    $groups = [];
    $missing = [];
    foreach ($items as $item) {
        $keys = event_plan_keys($item, $by);
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
    if ($missing !== []) {
        $none = ['players' => 'No sweet spot', 'age' => 'No age recorded', 'setting' => 'No setting', 'tag' => 'Untagged'];
        $groups[] = ['label' => $none[$by], 'items' => $missing];
    }
    return $groups;
}

/** The groups one item belongs to, as sort key => label. */
function event_plan_keys(array $item, $by) {
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
        if ($value !== '' && !isset($keys[$key])) {
            $keys[$key] = $value;
        }
    }
    return $keys;
}
