<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ItemsShortcut.bind(document, go).
 *
 * On signed-in and guest pages the Items nav link is the destination.
 * Pressing i (no modifiers, not typing in a textual field) calls go(href).
 * Pressing s focuses the Items search box marked data-shortcut="items-search".
 * Pressing f toggles the filter panel by clicking #display_filters.
 */
class ItemsShortcutTest extends TestCase
{
    public function test_pressing_i_opens_the_items_page(): void
    {
        $this->assertSame('/artifacts', $this->press(['key' => 'i']));
    }

    public function test_pressing_i_in_a_text_input_does_not_open_items(): void
    {
        $this->assertNull($this->press([
            'key' => 'i',
            'target' => ['tagName' => 'INPUT', 'type' => 'text'],
        ]));
    }

    public function test_pressing_i_in_the_items_search_box_does_not_open_items(): void
    {
        $this->assertNull($this->press([
            'key' => 'i',
            'target' => ['tagName' => 'INPUT', 'type' => 'search'],
        ]));
    }

    public function test_pressing_i_in_a_textarea_does_not_open_items(): void
    {
        $this->assertNull($this->press([
            'key' => 'i',
            'target' => ['tagName' => 'TEXTAREA'],
        ]));
    }

    public function test_pressing_i_on_a_checkbox_opens_items(): void
    {
        $this->assertSame('/artifacts', $this->press([
            'key' => 'i',
            'target' => ['tagName' => 'INPUT', 'type' => 'checkbox'],
        ]));
    }

    public function test_pressing_i_in_contenteditable_does_not_open_items(): void
    {
        $this->assertNull($this->press([
            'key' => 'i',
            'target' => ['tagName' => 'DIV', 'isContentEditable' => true],
        ]));
    }

    public function test_pressing_i_in_a_select_does_not_open_items(): void
    {
        $this->assertNull($this->press([
            'key' => 'i',
            'target' => ['tagName' => 'SELECT'],
        ]));
    }

    public function test_modifier_i_does_not_open_items(): void
    {
        $this->assertNull($this->press(['key' => 'i', 'ctrlKey' => true]));
        $this->assertNull($this->press(['key' => 'i', 'metaKey' => true]));
        $this->assertNull($this->press(['key' => 'i', 'altKey' => true]));
    }

    public function test_capital_i_opens_items(): void
    {
        $this->assertSame('/artifacts', $this->press(['key' => 'I']));
    }

    public function test_shortcut_is_idle_without_an_items_nav_link(): void
    {
        $this->assertNull($this->press(['key' => 'i'], null));
    }

    public function test_pressing_s_focuses_the_items_search_box(): void
    {
        $result = $this->runShortcut(['key' => 's']);
        $this->assertTrue($result['searchFocused']);
        $this->assertTrue($result['searchSelected']);
        $this->assertNull($result['navigated']);
    }

    public function test_pressing_s_in_a_text_input_does_not_steal_focus(): void
    {
        $result = $this->runShortcut([
            'key' => 's',
            'target' => ['tagName' => 'INPUT', 'type' => 'text'],
        ]);
        $this->assertFalse($result['searchFocused']);
    }

    public function test_capital_s_focuses_the_items_search_box(): void
    {
        $this->assertTrue($this->runShortcut(['key' => 'S'])['searchFocused']);
    }

    public function test_pressing_s_is_idle_without_a_search_box(): void
    {
        $result = $this->runShortcut(['key' => 's'], ['hasSearch' => false]);
        $this->assertFalse($result['searchFocused']);
        $this->assertNull($result['navigated']);
    }

    public function test_pressing_i_does_not_focus_search(): void
    {
        $result = $this->runShortcut(['key' => 'i']);
        $this->assertSame('/artifacts', $result['navigated']);
        $this->assertFalse($result['searchFocused']);
    }

    public function test_pressing_f_clicks_the_filters_button(): void
    {
        $result = $this->runShortcut(['key' => 'f'], ['hasFilters' => true]);
        $this->assertTrue($result['filtersClicked']);
        $this->assertNull($result['navigated']);
    }

    public function test_pressing_f_in_a_text_input_does_not_click_filters(): void
    {
        $result = $this->runShortcut([
            'key' => 'f',
            'target' => ['tagName' => 'INPUT', 'type' => 'text'],
        ], ['hasFilters' => true]);
        $this->assertFalse($result['filtersClicked']);
    }

    public function test_capital_f_clicks_the_filters_button(): void
    {
        $this->assertTrue($this->runShortcut(['key' => 'F'], ['hasFilters' => true])['filtersClicked']);
    }

    public function test_pressing_f_is_idle_without_a_filters_button(): void
    {
        $result = $this->runShortcut(['key' => 'f']);
        $this->assertFalse($result['filtersClicked']);
        $this->assertNull($result['navigated']);
    }

    public function test_header_loads_the_items_shortcut_script(): void
    {
        $header = file_get_contents(PROJECT_PATH . '/private/shared/header.php');
        $this->assertNotFalse($header);
        $this->assertStringContainsString('/shared/js/items-shortcut.js', $header);
    }

    public function test_signed_in_and_guest_items_links_expose_the_shortcut_target(): void
    {
        $header = file_get_contents(PROJECT_PATH . '/private/shared/header.php');
        $this->assertNotFalse($header);
        $this->assertSame(
            2,
            substr_count($header, 'data-shortcut="items"'),
            'Signed-in and guest Items links should each mark the shortcut destination.'
        );
        $this->assertSame(
            2,
            substr_count($header, 'url_for(\'/artifacts\'); ?>" data-shortcut="items">Items</a>'),
            'The shortcut target must be the Items nav destination, not another /artifacts URL.'
        );
    }

    public function test_items_page_marks_the_search_box_as_the_s_shortcut_target(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/index.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/id="items-search"[^>]*data-shortcut="items-search"|data-shortcut="items-search"[^>]*id="items-search"/',
            $source,
            'The items search box must be marked so s can focus it.'
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function press(array $event, ?string $itemsHref = '/artifacts'): ?string
    {
        return $this->runShortcut($event, ['itemsHref' => $itemsHref])['navigated'];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $opts
     * @return array{navigated: ?string, searchFocused: bool, searchSelected: bool, filtersClicked: bool}
     */
    private function runShortcut(array $event, array $opts = []): array
    {
        $itemsHref = array_key_exists('itemsHref', $opts) ? $opts['itemsHref'] : '/artifacts';
        $hasSearch = $opts['hasSearch'] ?? true;
        $hasFilters = $opts['hasFilters'] ?? false;

        $event += [
            'key' => 'i',
            'metaKey' => false,
            'ctrlKey' => false,
            'altKey' => false,
            'target' => ['tagName' => 'BODY'],
        ];
        $event['target'] += ['tagName' => 'BODY'];

        $module = json_encode(PROJECT_PATH . '/ui/shared/js/items-shortcut.js');
        $eventJson = json_encode($event);
        $hrefJson = json_encode($itemsHref);
        $hasSearchJson = json_encode($hasSearch);
        $hasFiltersJson = json_encode($hasFilters);
        $script = <<<JS
const ItemsShortcut = require({$module});
let navigated = null;
const listeners = {};
const link = {$hrefJson} ? { getAttribute: (name) => name === 'href' ? {$hrefJson} : null } : null;
const search = {$hasSearchJson} ? {
  tagName: 'INPUT',
  type: 'search',
  focused: false,
  selected: false,
  focus: function () { this.focused = true; },
  select: function () { this.selected = true; },
} : null;
const filtersButton = {$hasFiltersJson} ? {
  clicked: false,
  click: function () { this.clicked = true; },
} : null;
const doc = {
  querySelector: (sel) => {
    if (sel === '[data-shortcut="items"]') return link;
    if (sel === '[data-shortcut="items-search"]') return search;
    if (sel === '#display_filters') return filtersButton;
    return null;
  },
  addEventListener: (type, fn) => { listeners[type] = fn; },
};
ItemsShortcut.bind(doc, (url) => { navigated = url; });
const event = {$eventJson};
event.preventDefault = function () {};
if (listeners.keydown) {
  listeners.keydown(event);
}
process.stdout.write(JSON.stringify({
  navigated: navigated,
  searchFocused: !!(search && search.focused),
  searchSelected: !!(search && search.selected),
  filtersClicked: !!(filtersButton && filtersButton.clicked),
}));
JS;

        $cmd = 'node -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
