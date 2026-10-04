<?php

/**
 * The Event plan: an event's games as its page shows them under the chosen
 * grouping. They are grouped by sweet spot, age, the ages of the players
 * coming, setting or tag, then optionally by a second of those, and written
 * out as a packing checklist. A game belongs to every group its values name,
 * so one best at 6 and 8 shows under both "8 players" and "6 players".
 *
 * The interface is event_plan(), event_plan_grouping(),
 * event_plan_dimensions(), event_plan_details() and event_dates_label().
 * Every other function here is internal to the module and trusts that
 * event_plan() has already normalized the grouping.
 *
 * Items are rows with Title, MnP, MxP, SS, Age, MnT, MxT, tags, is_kept, and the
 * event's own setting, note and is_packed.
 */

require_once __DIR__ . '/item_facts.php';

/** The ways an event's games can be grouped, keyed by the value forms send. */
function event_plan_dimensions() {
    return [
        'none' => 'Nothing',
        'players' => 'Sweet spot',
        'age' => 'Age',
        'player_age' => "Players' ages",
        'setting' => 'Setting',
        'tag' => 'Tag',
    ];
}

/**
 * The Event plan for $event, as EventPlans::find() returns it (items with
 * tags, players with age), under $grouping, a saved or hand-built grouping
 * in event_plan_grouping()'s shape. Returns:
 *
 * - 'groups': each ['label' => ..., 'items' => [...], 'groups' => [...]],
 *   'groups' empty without a second grouping (see event_plan_groups());
 * - 'text': those groups as a plain-text checklist;
 * - 'packed': how many planned games are packed;
 * - 'shopping': ['items' => ..., 'text' => ...], the games not kept;
 * - 'spare': ['needed' => items, 'spare' => items, 'short' => [...], 'text'
 *   => the needed games' checklist, or '' when nothing can stay home] (see
 *   event_plan_spare());
 * - 'player_ages': the players' ages line;
 * - 'settings': the event's distinct settings, in natural order.
 *
 * Every item row carries 'can_stay_home', true for a game the smaller list
 * leaves out.
 */
function event_plan(array $event, array $grouping) {
    $grouping = event_plan_grouping([], $grouping);
    // The grouping keeps a repeated choice so the form shows what was picked;
    // the plan treats it as no second grouping.
    $view = [
        'by' => $grouping['by'],
        'then' => $grouping['then'] === $grouping['by'] ? 'none' : $grouping['then'],
        'tags' => event_plan_chosen_tags($grouping['tags']),
        'ages' => event_plan_age_groups(array_column($event['players'], 'age')),
    ];

    $spare = event_plan_spare(event_plan_sorted_by_title($event['items']), $view,
        $grouping['at_least'], event_plan_chosen_tags($grouping['count_tags']));
    $items = $spare['items'];
    $needed = [];
    $can_stay_home = [];
    foreach ($items as $item) {
        if ($item['can_stay_home']) {
            $can_stay_home[] = $item;
        } else {
            $needed[] = $item;
        }
    }
    $groups = event_plan_groups($items, $view);

    // Read in the event's own order, so settings differing only in case keep their order.
    $settings = array_values(array_unique(array_filter(array_map('trim', array_column($event['items'], 'setting')))));
    sort($settings, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'groups' => $groups,
        'text' => event_plan_text($groups),
        'packed' => count(array_filter(array_column($items, 'is_packed'))),
        'shopping' => event_plan_shopping_list($items),
        'spare' => [
            'needed' => $needed,
            'spare' => $can_stay_home,
            'short' => $spare['short'],
            'text' => $can_stay_home === [] ? '' : event_plan_text(event_plan_groups($needed, $view)),
        ],
        'player_ages' => event_player_ages($event['players']),
        'settings' => $settings,
    ];
}

/**
 * Internal. The items, in title order, grouped by $view's 'by', each
 * group's items grouped again by its 'then'. Each group is ['label' => ...,
 * 'items' => [...], 'groups' => [...]], where 'groups' is empty without a
 * second grouping. Items with nothing to group by collect in a last group
 * such as "No sweet spot"; within a group they come first and unlabelled,
 * the way a hand-written list leaves them.
 *
 * $view's 'tags', when given, are the only tags that make groups, in that
 * order, so "casual, main" splits each player count in two and ignores
 * "beach-safe".
 *
 * $view's 'ages' are the players' age groups (see event_plan_age_groups()).
 * By 'player_age', each distinct child age and "Adults" make a group, and a
 * game shows once, under the youngest of them old enough for it.
 */
function event_plan_groups(array $items, array $view) {
    $groups = [];
    foreach (event_plan_split($items, $view['by'], $view, false) as $group) {
        $group['groups'] = $view['then'] === 'none' ? [] : array_map(function ($sub) {
            return $sub + ['groups' => []];
        }, event_plan_split($group['items'], $view['then'], $view, true));
        $groups[] = $group;
    }
    return $groups;
}

/**
 * The grouping a request asks for (by, then, tags, at_least, count_tags),
 * each part falling back to the saved one and then to "Sweet spot, nothing,
 * no tags, no count". Naming tags with no second grouping sub-groups by tag,
 * since the tags would otherwise do nothing. at_least is the number of games
 * each group should keep and count_tags the tags it holds for (see
 * event_plan_spare()); a blank at_least clears it to 0, meaning none.
 */
function event_plan_grouping(array $request, array $saved) {
    $saved += ['by' => 'players', 'then' => 'none', 'tags' => '', 'at_least' => 0, 'count_tags' => ''];
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
    $at_least = $request['at_least'] ?? null;
    if (is_string($at_least) && trim($at_least) === '') {
        $at_least = 0;
    } elseif (is_string($at_least) && preg_match('/^\s*\d{1,2}\s*$/', $at_least)) {
        $at_least = (int) $at_least;
    } else {
        $at_least = max(0, min(99, (int) $saved['at_least']));
    }
    $count_tags = is_string($request['count_tags'] ?? null) ? $request['count_tags'] : (string) $saved['count_tags'];
    $count_tags = implode(', ', event_plan_chosen_tags($count_tags));
    return ['by' => $by, 'then' => $then, 'tags' => $tags, 'at_least' => $at_least, 'count_tags' => $count_tags];
}

/**
 * Internal. Which planned games a smaller plan can leave home and still have
 * $at_least games in every group the grouping makes: each labelled
 * sub-group, such as "6 players · casual", or each group without a second
 * grouping. A game outside every group, such as one with none of the chosen
 * tags, counts toward nothing and is always spare. With $count_tags, only
 * the groups for those tags need the count, so "casual, main" can leave
 * "kids" groups out. Items are told apart by id, which an event never
 * repeats.
 *
 * A group with fewer games than $at_least is 'short', and needs every game
 * it has. The 'needed' games are picked greedily, most uncovered groups
 * first and kept games ahead of ones to buy, then pruned until dropping any
 * one of them would leave a group short: a small set, though not always the
 * smallest possible.
 *
 * $items come in title order. Returns ['items' => the items, each with
 * 'can_stay_home', 'short' => [['label' => '8 players · casual', 'count' =>
 * 1], ...]], short groups in the plan's order.
 */
function event_plan_spare(array $items, array $view, $at_least, array $count_tags) {
    $with_can_stay_home = function (array $chosen) use ($items) {
        foreach (array_keys($items) as $i) {
            $items[$i]['can_stay_home'] = !isset($chosen[$i]);
        }
        return $items;
    };
    if ($at_least <= 0) {
        return ['items' => $with_can_stay_home(array_fill_keys(array_keys($items), true)), 'short' => []];
    }
    ['by' => $by, 'then' => $then] = $view;

    // Reading the groups off event_plan_groups() keeps them, and their
    // order, the same as the page shows.
    $position = [];
    foreach ($items as $i => $item) {
        $position[$item['id']] = $i;
    }
    $cells = [];
    foreach (event_plan_groups($items, $view) as $group) {
        // The last group, such as "No sweet spot", gathers games without a value.
        if ($by !== 'none' && event_plan_keys($group['items'][0], $by, $view) === []) {
            continue;
        }
        foreach ($group['groups'] ?: [['label' => '', 'items' => $group['items']]] as $sub) {
            // Unlabelled sub-groups gather games without the second value.
            if ($then !== 'none' && $sub['label'] === '') {
                continue;
            }
            $tag = $by === 'tag' ? $group['label'] : ($then === 'tag' ? $sub['label'] : null);
            if ($count_tags !== [] && $tag !== null && !in_array(mb_strtolower($tag), $count_tags, true)) {
                continue;
            }
            $label = implode(' · ', array_filter([$group['label'], $sub['label']], 'strlen'));
            $members = [];
            foreach ($sub['items'] as $item) {
                $members[] = $position[$item['id']];
            }
            $cells[] = ['label' => $label, 'members' => $members, 'need' => min($at_least, count($members))];
        }
    }

    $in = [];
    foreach ($cells as $c => $cell) {
        foreach ($cell['members'] as $i) {
            $in[$i][] = $c;
        }
    }
    $have = array_fill(0, count($cells), 0);
    $chosen = [];
    // Greedy: the game filling the most still-open groups, kept games first.
    while (true) {
        $best = null;
        $best_rank = null;
        foreach ($in as $i => $cs) {
            if (isset($chosen[$i])) {
                continue;
            }
            $gain = 0;
            foreach ($cs as $c) {
                $gain += $have[$c] < $cells[$c]['need'] ? 1 : 0;
            }
            if ($gain === 0) {
                continue;
            }
            $rank = [$gain, event_plan_is_to_buy($items[$i]) ? 0 : 1, count($cs)];
            if ($best_rank === null || $rank > $best_rank) {
                [$best, $best_rank] = [$i, $rank];
            }
        }
        if ($best === null) {
            break;
        }
        $chosen[$best] = true;
        foreach ($in[$best] as $c) {
            $have[$c]++;
        }
    }
    // Prune: drop any game every one of whose groups has enough without it,
    // games to buy and those in the fewest groups first.
    $order = array_keys($chosen);
    usort($order, function ($a, $b) use ($items, $in) {
        return [event_plan_is_to_buy($items[$b]), count($in[$a]), $b] <=> [event_plan_is_to_buy($items[$a]), count($in[$b]), $a];
    });
    foreach ($order as $i) {
        $droppable = true;
        foreach ($in[$i] as $c) {
            $droppable = $droppable && $have[$c] > $cells[$c]['need'];
        }
        if ($droppable) {
            unset($chosen[$i]);
            foreach ($in[$i] as $c) {
                $have[$c]--;
            }
        }
    }

    $short = [];
    foreach ($cells as $cell) {
        if (count($cell['members']) < $at_least) {
            $short[] = ['label' => $cell['label'], 'count' => count($cell['members'])];
        }
    }
    return ['items' => $with_can_stay_home($chosen), 'short' => $short];
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

/** The age from which an event's player counts as an adult. */
const EVENT_ADULT_AGE = 18;

/**
 * Internal. How old an event's players are, as "2 adults (18+) · 3 children: 1 age
 * 12, 2 age 8 · 1 age unknown", children oldest first, or '' with no
 * players. Players are rows with an 'age', null without a birth year.
 */
function event_player_ages(array $players) {
    $adults = 0;
    $unknown = 0;
    $children = [];
    foreach ($players as $player) {
        $age = $player['age'] ?? null;
        if ($age === null) {
            $unknown++;
        } elseif ($age >= EVENT_ADULT_AGE) {
            $adults++;
        } else {
            $children[$age] = ($children[$age] ?? 0) + 1;
        }
    }
    krsort($children);

    $parts = [];
    if ($adults > 0) {
        $parts[] = $adults . ($adults === 1 ? ' adult' : ' adults') . ' (' . EVENT_ADULT_AGE . '+)';
    }
    if ($children) {
        $count = array_sum($children);
        $by_age = [];
        foreach ($children as $age => $n) {
            $by_age[] = $n . ' age ' . $age;
        }
        $parts[] = $count . ($count === 1 ? ' child: ' : ' children: ') . implode(', ', $by_age);
    }
    if ($unknown > 0) {
        $parts[] = $unknown . ' age unknown';
    }
    return implode(' · ', $parts);
}

/**
 * Internal. The age groups the players make, youngest first: each distinct child age,
 * then EVENT_ADULT_AGE standing for every adult. Unknown ages are left out.
 */
function event_plan_age_groups(array $player_ages) {
    $groups = [];
    foreach ($player_ages as $age) {
        if ($age !== null) {
            // A birth year after the event would make a negative age.
            $groups[] = max(0, min((int) $age, EVENT_ADULT_AGE));
        }
    }
    $groups = array_values(array_unique($groups));
    sort($groups);
    return $groups;
}

/**
 * Internal. The planned items not kept, such as games to buy before the
 * event, in the order given (title order): 'items' with the event's setting and packed mark left out
 * (a note such as "requested by mom" stays), and 'text' as an unticked checklist, "- [ ] Wavelength, 2–12".
 */
function event_plan_shopping_list(array $items) {
    $to_buy = array_values(array_filter($items, 'event_plan_is_to_buy'));
    $to_buy = array_map(function ($item) {
        return array_diff_key($item, array_flip(['is_kept', 'setting', 'is_packed']));
    }, $to_buy);
    $text = implode('', array_map(function ($item) {
        return '- [ ] ' . event_plan_line($item) . "\n";
    }, $to_buy));
    return ['items' => $to_buy, 'text' => $text];
}

/** Internal. Whether a row says its item is not kept, so it would have to be bought. */
function event_plan_is_to_buy(array $item) {
    return array_key_exists('is_kept', $item) && !$item['is_kept'];
}

/** Internal. The items in title order, ties by id. */
function event_plan_sorted_by_title(array $items) {
    usort($items, function ($a, $b) {
        return strcasecmp((string) $a['Title'], (string) $b['Title']) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
    });
    return $items;
}

/** Internal. The groups as a checklist: "# group", "## sub-group", "- [ ] line". */
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
 * Internal. One game as the checklist names it: "Hanabi, 2–5 (4), 10 yrs, 25 min,
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
    // item_copy_text() leads with the title; keep what follows it.
    $parts = [substr(item_copy_text($item), strlen($title))];
    $time = item_play_time($item);
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
    if (event_plan_is_to_buy($item)) {
        $parts[] = ', not kept';
    }
    return implode('', $parts);
}

/** Internal. The items as checklist lines, ticked when packed. */
function event_plan_checklist(array $items) {
    return array_map(function ($item) {
        return '- [' . (empty($item['is_packed']) ? ' ' : 'x') . '] ' . event_plan_line($item);
    }, $items);
}

/** Internal. Chosen tags, as typed ("casual, main") or a list, normalized and in order. */
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
 * Internal. Items (already in title order) split into ordered groups by one
 * dimension, $by, with $view's tags and ages. Items lacking a value form a
 * last, labelled group, or with $is_sub a first, unlabelled one.
 */
function event_plan_split(array $items, $by, array $view, $is_sub) {
    if ($by === 'none') {
        return [['label' => '', 'items' => $items]];
    }
    $groups = [];
    $missing = [];
    foreach ($items as $item) {
        $keys = event_plan_keys($item, $by, $view);
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
    } elseif ($by === 'age' || $by === 'player_age') {
        ksort($groups, SORT_NUMERIC);
    } else {
        ksort($groups, SORT_STRING);
    }
    $groups = array_values($groups);
    if ($missing !== [] && $is_sub) {
        array_unshift($groups, ['label' => '', 'items' => $missing]);
    } elseif ($missing !== []) {
        $none = ['players' => 'No sweet spot', 'age' => 'No age recorded', 'setting' => 'No setting', 'tag' => 'Untagged',
            'player_age' => $view['ages'] === [] ? 'No player ages recorded' : 'No age recorded'];
        $groups[] = ['label' => $none[$by], 'items' => $missing];
    }
    return $groups;
}

/** Internal. The groups one item belongs to by $by, as sort key => label. */
function event_plan_keys(array $item, $by, array $view) {
    ['tags' => $tags, 'ages' => $ages] = $view;
    if ($by === 'players') {
        $keys = [];
        foreach (item_sweet_spot_counts($item) as $n) {
            $keys[$n] = $n . ($n === 1 ? ' player' : ' players');
        }
        return $keys;
    }
    if ($by === 'player_age') {
        $age = item_min_age($item);
        if ($age === null || $ages === []) {
            return [];
        }
        foreach ($ages as $group) {
            // Adults can play whatever age a game asks.
            if ($group >= $age || $group === EVENT_ADULT_AGE) {
                return [$group => $group === EVENT_ADULT_AGE ? 'Adults' : 'Age ' . $group];
            }
        }
        return [EVENT_ADULT_AGE + 1 => "Older than the players' known ages"];
    }
    if ($by === 'age') {
        $age = item_min_age($item);
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
