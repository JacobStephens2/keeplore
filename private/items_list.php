<?php

require_once __DIR__ . '/kept_status.php';
require_once __DIR__ . '/item_types.php';
require_once __DIR__ . '/item_facts.php';
require_once __DIR__ . '/use_by_date.php';
require_once __DIR__ . '/app_day.php';
require_once __DIR__ . '/classes/Items.php';
require_once __DIR__ . '/classes/BggRatings.php';
require_once __DIR__ . '/classes/Preferences.php';

require_once __DIR__ . '/type_filter.php';

/**
 * The owner's Items page: raw requests become complete page metadata or
 * asynchronous rows. Each operation reads current owner defaults. Metadata
 * needs Type counts and reviewer names, but never the collection or Use
 * history; data needs no reviewer-column metadata. Storage failures propagate.
 */
final class ItemsPage
{
    private string $today;

    /** $today is a Y-m-d App day, captured once when omitted. */
    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? app_today();
    }

    /** All form state, navigation and table descriptions; no full Item rows. */
    public function metadata(string $method, array $get, array $post): array
    {
        [$filters, $types, $defaultInterval] = $this->request($method, $get, $post);
        $query = fn (array $changes = []) => $this->filterQuery($filters, $types, $defaultInterval, $changes);
        $heading = $this->heading($filters['players'], $filters['age']);
        $pageTitle = $heading ?? 'Items';
        if ($filters['kept'] === 'secondary_only') {
            $pageTitle .= ' (Secondary Only)';
        }

        $keptActive = match ($filters['kept']) {
            'secondary_only' => null,
            'allkeptandnot' => 'all',
            default => $filters['kept'],
        };
        $keptOptions = [];
        foreach (['all' => 'All', 'yes' => 'Kept', 'no' => 'Not kept'] as $value => $label) {
            $keptOptions[$value] = [
                'label' => $label,
                'active' => $keptActive === $value,
                'query' => $query(['kept' => $value === 'all' ? null : $value]),
            ];
        }
        $typeSwitch = $this->typeSwitch($types, $filters['type']);
        foreach ($typeSwitch['options'] as $value => &$option) {
            $option['active'] = $typeSwitch['active'] === $value;
            $option['query'] = $query(['type' => $value === 'all' ? null : $option['type_ids']]);
        }
        unset($option);

        $selected = type_filter_owned($types, $filters['type'] ?? []);
        $pickerQuery = $query(['players' => null, 'age' => null, 'ageUnknown' => null]);
        return [
            'filters' => $filters,
            'heading' => $heading,
            'page_title' => $pageTitle,
            'columns' => $this->columns($filters, (new BggRatings($this->db, $this->userId))->reviewers()),
            'empty_message' => $heading === null ? 'No items yet.' : 'No items are ' . lcfirst($heading) . '.',
            'type_filter' => ['types' => $types, 'selected' => $selected],
            'type_filter_active' => $selected !== [] && $selected !== type_filter_owned($types, $types),
            'kept_switch' => ['options' => $keptOptions, 'active' => $keptActive],
            'type_switch' => $typeSwitch,
            'picker' => ['hidden_fields' => $this->hiddenFields($pickerQuery), 'clear_query' => $pickerQuery],
            'filter_panel_hidden_fields' => $this->hiddenFields($query([
                'kept' => null, 'type' => null, 'tagFilter' => null, 'showAttributes' => null,
            ])),
            'data_query' => $query(),
        ];
    }

    /** The later GET reply. Empty Type selections may be omitted in transit. */
    public function data(array $get): array
    {
        [$filters] = $this->request('GET', $get, []);
        $items = $this->payload($filters);
        return ['items' => $items, 'count' => count($items)];
    }

    private function request(string $method, array $get, array $post): array
    {
        $defaultInterval = (new Preferences($this->db, $this->userId))->get()['default_use_interval'];
        $types = array_column((new Types($this->db, $this->userId))->all(), 'id', 'name');
        return [$this->filtersFromRequest($get, $post, $method, $defaultInterval, $types), $types, $defaultInterval];
    }

    private function filtersFromRequest(
        array $get,
        array $post,
        string $method,
        $default_interval,
        array $all_types
    ) {
        $is_post = strtoupper($method) === 'POST';

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
        $players = $this->positiveInt($players);
        // The youngest player's age: items must be recommended for this age or younger.
        $age = $this->positiveInt($post['age'] ?? $get['age'] ?? '');
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
    private function typeSwitch(array $all_types, array $current_type_ids) {
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

        $current = $this->typeIds($current_type_ids);
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
    private function columns(array $filters, array $bgg_reviewers) {
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
    private function positiveInt($value) {
        return is_string($value) && preg_match('/^\s*[1-9]\d*\s*$/', $value) ? (int) $value : null;
    }

    /**
     * The Items page's GET query for the filters with $changes applied, keyed by
     * filter name, where null puts a filter back to its default. A default is
     * left out, since the request parser reads its absence as the default: kept
     * for all items, a Type list that is empty or every Type, the default
     * interval, no players or age, attributes off and a blank tag. Every link,
     * carried field and the list's data URL on the page comes from this.
     */
    private function filterQuery(array $filters, array $all_types, $default_interval, array $changes = []) {
        $filters = array_replace($filters, $changes);
        $query = [];

        $kept = $filters['kept'] ?? 'allkeptandnot';
        if (in_array($kept, ['yes', 'no', 'secondary_only'], true)) {
            $query['kept'] = $kept;
        }

        $type_ids = $this->typeIds($filters['type'] ?? []);
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
    private function hiddenFields(array $query) {
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
    private function typeIds($type) {
        $ids = [];
        foreach ((array) $type as $id) {
            if ($id !== '' && $id !== null) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /**
     * Reviewer ratings for one Items row. A numeric rating carries score_text
     * in the score spelling and sort as the stored rating. A comment with no
     * score does not carry score_text, and its sort is 0. site_name is the
     * site of the item's link, or BoardGameGeek when the item has no link.
     */
    private function presentRatings($ratings) {
        if (empty($ratings) || !is_array($ratings)) {
            return new stdClass();
        }
        $presented = [];
        foreach ($ratings as $reviewer => $rating) {
            if (!is_array($rating)) {
                continue;
            }
            if (isset($rating['rating']) && is_numeric($rating['rating'])) {
                $rating['score_text'] = bgg_score_text($rating['rating']);
                $rating['sort'] = (float) $rating['rating'];
            } else {
                $rating['sort'] = 0;
            }
            $rating['site_name'] = bgg_site_name_from_url($rating['url'] ?? '');
            $presented[$reviewer] = $rating;
        }
        return $presented === [] ? new stdClass() : $presented;
    }

    /**
     * The count Players sorts by: the low end of the player range when one is
     * recorded, otherwise the lowest Sweet spot count, or null when neither is.
     */
    private function playersSort(array $item) {
        $low = item_player_range_low($item);
        if ($low !== null) {
            return $low;
        }
        $counts = item_sweet_spot_counts($item);
        return $counts === [] ? null : $counts[0];
    }

    /**
     * One Items row for display. $artifact is a row from Items::list: its
     * last_use is Recent Interaction and its type_name the Type. The module's App day
     * determines whether a kept Item is overdue.
     */
    private function presentRow(array $artifact, $default_interval) {
        $last_use = (string) ($artifact['last_use'] ?? '');

        // The view's interval is a default only; the item's own frequency wins.
        $use_by = use_by_status($artifact, $default_interval, $this->today);

        // Only a kept item is overdue here.
        $is_kept = artifact_is_kept($artifact);
        $overdue = $is_kept && $use_by['status'] === 'overdue';

        $min_age = item_min_age($artifact);
        $bgg_average = bgg_overall_rating_text($artifact['BGG_Rat'] ?? $artifact['bgg_rat'] ?? null);

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
            'players_sort' => $this->playersSort($artifact),
            'age' => $min_age === null ? '' : $min_age . '+',
            'age_sort' => $min_age,
            'copy_text' => item_copy_text($artifact),
            'time' => item_play_time($artifact),
            'time_sort' => item_play_time_low($artifact),
            'avg_time' => item_average_play_time($artifact),
            'candidate' => item_is_candidate($artifact),
            'bgg_average' => $bgg_average ?? '',
            'bgg_average_sort' => $bgg_average === null ? null : (float) $bgg_average,
            // Keyed by BGG username; an object even when empty so the JSON is {}.
            'bgg_ratings' => $this->presentRatings($artifact['bgg_ratings'] ?? null),
        ];
    }

    /**
     * The Items page's rows: the owner's Items from Items::list, narrowed by the
     * page's players and youngest-age filters, newest acquisition first, then
     * kept first, then id.
     */
    private function payload(array $filters) {
        $artifacts = (new Items($this->db, $this->userId))->list($this->itemFilters($filters));
        usort($artifacts, fn (array $a, array $b) =>
            [(string) ($b['Acq'] ?? ''), artifact_is_kept($b), (int) $a['id']]
            <=> [(string) ($a['Acq'] ?? ''), artifact_is_kept($a), (int) $b['id']]);
        if ($filters['players'] !== null) {
            $artifacts = array_filter($artifacts, fn (array $item) => item_plays_best_at($item, $filters['players']));
        }
        if ($filters['age'] !== null) {
            $artifacts = array_filter($artifacts, fn (array $item) => item_suits_age($item, $filters['age'], $filters['ageUnknown']));
        }
        $bgg_ratings = (new BggRatings($this->db, $this->userId))->forItems(array_column($artifacts, 'id'));
        $items = [];
        foreach ($artifacts as $artifact) {
            $artifact['bgg_ratings'] = $bgg_ratings[(int) $artifact['id']] ?? [];
            $items[] = $this->presentRow($artifact, $filters['interval']);
        }
        return $items;
    }

    /**
     * Items::list's filters for the page's kept, type and tag choices. An empty
     * type list means every type; blank type entries are dropped, so a list of
     * only blanks lists nothing.
     */
    private function itemFilters(array $filters) {
        $item_filters = match ($filters['kept']) {
            'yes' => ['kept' => true],
            'no' => ['kept' => false],
            'secondary_only' => ['secondary_collection' => true],
            default => [],
        };
        $type = (array) ($filters['type'] ?? []);
        if ($type !== []) {
            $item_filters['type_ids'] = $this->typeIds($type);
        }
        $item_filters['tag'] = $filters['tagFilter'];
        return $item_filters;
    }

    /**
     * The title for a chosen count and youngest age, as in "Best at 3 players,
     * suitable for age 2", or null with neither.
     */
    private function heading($players, $age) {
        $parts = [];
        if ($players !== null) {
            $parts[] = 'Best at ' . $players . ($players === 1 ? ' player' : ' players');
        }
        if ($age !== null) {
            $parts[] = ($parts ? 'suitable' : 'Suitable') . ' for age ' . $age;
        }
        return $parts ? implode(', ', $parts) : null;
    }
}
