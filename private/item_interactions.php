<?php

require_once __DIR__ . '/app_day.php';
require_once __DIR__ . '/item_facts.php';
require_once __DIR__ . '/type_filter.php';
require_once __DIR__ . '/classes/Preferences.php';
require_once __DIR__ . '/classes/Uses.php';

/** The owner's complete Item Interactions page read. */
final class ItemInteractionsPage
{
    private string $today;

    /** $today is a Y-m-d day; the App day when omitted. */
    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? app_today();
    }

    /**
     * Only the shared POST Type selection changes in $session. A date
     * validation error leaves the form available with no table or counts.
     * Unexpected storage failures propagate.
     */
    public function read(string $method, array $post, array &$session): array
    {
        $typeFilter = type_filter($this->db, $this->userId, $method, $post, $session);
        $post = strtoupper($method) === 'POST' ? $post : [];
        $minimumDate = $post['minimumDate'] ?? '';
        $duplicateChoice = $post['hide_duplicate_group_settings'] ?? 'no';
        $filters = [
            'minimumDate' => is_string($minimumDate) ? $minimumDate : '',
            'showAttributes' => ($post['showAttributes'] ?? 'no') === 'yes',
            'hide_duplicate_group_settings' => $duplicateChoice === 'yes',
            'hide_online_setting' => ($post['hide_online_setting'] ?? 'no') === 'yes',
        ];
        $view = [
            'type_filter' => $typeFilter,
            'filters' => $filters,
            'date_hint' => $this->dateHint(),
            'error' => null,
            'table' => null,
        ];

        try {
            $uses = (new Uses($this->db, $this->userId))->all([
                'type_ids' => $typeFilter['selected'],
                'since' => $minimumDate,
            ]);
        } catch (InvalidArgumentException $error) {
            $view['error'] = $error->getMessage();
            return $view;
        }

        $visibleUses = $filters['hide_online_setting']
            ? array_filter($uses, fn (array $use) => $use['setting'] !== 'online')
            : $uses;
        // Candidate spot matches can come from any of this owner's Items.
        $items = $filters['showAttributes'] && $visibleUses !== []
            ? (new Items($this->db, $this->userId))->list()
            : [];
        $table = $this->projectRows($visibleUses, $items, $filters['hide_duplicate_group_settings']);
        $table['use_count'] = count($uses);
        $view['table'] = $table + $this->layout($filters, $duplicateChoice === 'no', $table);
        return $view;
    }

    private function dateHint(): array
    {
        $interval = (new Preferences($this->db, $this->userId))->get()['default_use_interval'];
        [$year, $month, $day] = explode('-', app_days_before($this->today, $interval));
        return [
            'interval' => rtrim(rtrim(number_format($interval, 2, '.', ''), '0'), '.'),
            'cutoff' => "$month/$day/$year",
        ];
    }

    /** Uses arrive newest first and Items arrive in title/ID order. */
    private function projectRows(array $uses, array $items, bool $hideDuplicates): array
    {
        $itemsById = array_column($items, null, 'id');
        $groupSettings = [];
        $groupSettingItems = [];
        $rows = [];

        foreach ($uses as $use) {
            $personCount = count($use['people']);
            $groupSetting = $this->groupSetting($use['people'], $use['setting'], $personCount);
            $groupSettings[$groupSetting] = true;

            $groupSettingItem = $groupSetting . ' (' . $use['item_title'];
            if ($hideDuplicates && isset($groupSettingItems[$groupSettingItem])) {
                continue;
            }
            $groupSettingItems[$groupSettingItem] = true;

            $item = $itemsById[$use['item_id']] ?? null;
            $rows[] = [
                'id' => $use['id'],
                'use_date' => $use['use_date'],
                'item_id' => $use['item_id'],
                'item_title' => $use['item_title'],
                'item_type' => (string) $use['item_type'],
                'setting' => $use['setting'],
                'person_count' => $personCount,
                'group_setting' => $groupSettingItem . ' on ' . $use['use_date'] . ')',
                'candidate' => $item !== null && item_is_candidate($item),
                'sweet_spot' => $item === null ? '' : (string) item_facts_column($item, 'SS'),
                'candidate_spot' => $this->candidateSpot($groupSettingItem, $items),
            ];
        }

        return [
            'rows' => $rows,
            'group_and_setting_count' => count($groupSettings),
            'group_setting_item_count' => count($groupSettingItems),
        ];
    }

    private function groupSetting(array $people, string $setting, int $personCount): string
    {
        $names = [];
        foreach ($people as $person) {
            $names[$person['id']] = $person['first_name'] . ' ' . $person['last_name'];
        }
        ksort($names, SORT_NUMERIC);
        $nameList = implode(', ', $names);
        // Legacy duplicate links count separately, even when the name is printed once.
        if (count($names) < $personCount) {
            $nameList .= ', ';
        }
        return sprintf('%02d: %s%s %s', $personCount, $nameList, $setting === 'online' ? '' : ' at', $setting);
    }

    private function candidateSpot(string $groupSettingItem, array $items): ?string
    {
        $prefix = mb_strtolower($groupSettingItem);
        foreach ($items as $item) {
            $note = mb_strtolower((string) item_facts_column($item, 'Candidate'));
            if (str_starts_with($note, $prefix)) {
                return $item['Title'];
            }
        }
        return null;
    }

    /** The same column keys drive rendering, count placement and sort indices. */
    private function layout(array $filters, bool $showDateCount, array $table): array
    {
        $columns = [
            'use_date' => 'Interaction Date',
            'item_title' => 'Item',
            'group_setting' => 'Group Setting',
            'item_type' => 'Type',
            'setting' => 'Setting',
        ];
        if ($filters['showAttributes']) {
            $columns += [
                'candidate' => 'Candidate',
                'sweet_spot' => 'SwS',
                'person_count' => 'User Count',
                'candidate_spot' => 'Candidate Spot Used',
            ];
        }

        $filtered = $filters['hide_online_setting'] || $filters['hide_duplicate_group_settings'];
        $headingCount = $filtered ? $table['group_setting_item_count'] : null;
        $order = ['use_date' => 'desc'];
        if (!$filtered) {
            $order['group_setting'] = 'asc';
        } elseif ($filters['showAttributes']) {
            $order = ['candidate' => 'desc'] + $order;
        }
        $indices = array_flip(array_keys($columns));
        $sort = [];
        foreach ($order as $key => $direction) {
            $sort[] = [$indices[$key], $direction];
        }

        return [
            'columns' => $columns,
            'column_counts' => [
                'use_date' => $showDateCount ? $table['use_count'] : null,
                'group_setting' => $headingCount,
            ],
            'heading_count' => $headingCount,
            'order' => $sort,
        ];
    }
}
