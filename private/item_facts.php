<?php

/**
 * Item facts: an item's play facts read from its row. These are its sweet
 * spot, recommended minimum age, player range and play time, and the labels
 * pages show for them. Every page that filters or labels items by these facts
 * reads them here, so the Sweet spot rule lives in one place.
 *
 * Each function takes an item row and reads its columns whichever way they
 * are spelled: games' SS, Age, MnP, MxP, MnT and MxT, or the lowercase
 * spellings some queries return.
 */

/**
 * The player counts the item's sweet spot names, ascending. Stored sweet
 * spots come in every spelling the field has had: BGG's zero-padded "03,04",
 * hand-typed "3, 4", and ranges such as "06-8".
 */
function item_sweet_spot_counts(array $item) {
    $counts = [];
    // Close up "3 - 6" to "3-6" first, so the split below keeps the range whole.
    $ss = preg_replace('/\s*([-–])\s*/u', '$1', trim((string) item_facts_column($item, 'SS')));
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

/** Whether the item's sweet spot includes the player count. */
function item_plays_best_at(array $item, int $players) {
    return in_array($players, item_sweet_spot_counts($item), true);
}

/** The item's recommended minimum age, or null when none is recorded. */
function item_min_age(array $item) {
    $age = (int) item_facts_column($item, 'Age');
    return $age > 0 ? $age : null;
}

/**
 * Whether the item is recommended for a Youngest age: its minimum age is at
 * or below it. An item with no recorded minimum age is left out, since
 * nothing vouches for it, unless the caller asks to include unknown ages.
 */
function item_suits_age(array $item, int $age, bool $include_unknown = false) {
    $min_age = item_min_age($item);
    return $min_age === null ? $include_unknown : $min_age <= $age;
}

/** The item's player range, as in "2–4", "3" or '' when none is recorded. */
function item_player_range(array $item) {
    return item_facts_range(item_facts_column($item, 'MnP'), item_facts_column($item, 'MxP'));
}

/** The item's play time, as in "30–60 min", "45 min", or '' when none is recorded. */
function item_play_time(array $item) {
    // A time range reads like a player range: "30–60", or "45" with one end.
    $range = item_facts_range(item_facts_column($item, 'MnT'), item_facts_column($item, 'MxT'));
    return $range === '' ? '' : $range . ' min';
}

/**
 * The sweet spot's counts, as in "3" or "3, 4". A run of three or more
 * consecutive counts reads as a range, so "3–5" but "3, 4".
 */
function item_best_counts_label(array $item) {
    $runs = [];
    foreach (item_sweet_spot_counts($item) as $n) {
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
function item_players_label(array $item) {
    $range = item_player_range($item);
    $best = item_best_counts_label($item);
    if ($best === '') {
        return $range;
    }
    return $range === '' ? 'best ' . $best : $range . ' (best ' . $best . ')';
}

/**
 * The line Items' Copy button puts on the clipboard for sharing a game:
 * "Azul, 2–4 (2), 8 yrs", the name, the player range with its sweet spot,
 * and the minimum age. A part with nothing recorded is left out.
 */
function item_copy_text(array $item) {
    $parts = [(string) ($item['Title'] ?? '')];
    $range = item_player_range($item);
    $best = item_best_counts_label($item);
    if ($range !== '') {
        $parts[] = $best === '' ? $range : $range . ' (' . $best . ')';
    } elseif ($best !== '') {
        $parts[] = 'best ' . $best;
    }
    $min_age = item_min_age($item);
    if ($min_age !== null) {
        $parts[] = $min_age . ' yrs';
    }
    return implode(', ', $parts);
}

/**
 * The item's play facts on one line, as in "2–4 players, best 3 ·
 * 30–60 min · Age 8+": the player range with its sweet spot, the play time
 * unless $with_time is false, and the minimum age. A part with nothing
 * recorded is left out; '' when nothing is.
 */
function item_play_facts(array $item, bool $with_time = true) {
    $range = item_player_range($item);
    $best = item_best_counts_label($item);
    $parts = [];
    if ($range !== '') {
        $players = $range . ($range === '1' ? ' player' : ' players');
        $parts[] = $best === '' ? $players : $players . ', best ' . $best;
    } elseif ($best !== '') {
        $parts[] = 'Best at ' . $best;
    }
    $time = $with_time ? item_play_time($item) : '';
    if ($time !== '') {
        $parts[] = $time;
    }
    $min_age = item_min_age($item);
    if ($min_age !== null) {
        $parts[] = 'Age ' . $min_age . '+';
    }
    return implode(' · ', $parts);
}

/** Internal. A column as games spells it, such as 'MnP', or in lowercase. */
function item_facts_column(array $item, string $column) {
    return $item[$column] ?? $item[strtolower($column)] ?? null;
}

/** Internal. A range of two recorded numbers, as in "2–4", "3" or ''. */
function item_facts_range($min, $max) {
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
