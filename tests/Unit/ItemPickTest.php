<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ItemPick.bind (ui/shared/js/item-pick.js).
 *
 * The ten-result list, the result keys, an empty query, exact match,
 * a stale response, a failed search, select, the substring match, and
 * the collection-list request. Page tests keep script order, Create
 * and select, the person-field hide, and the absence of the shared
 * search box.
 */
class ItemPickTest extends TestCase
{
    public function test_a_blank_query_does_not_search_and_clears_the_list_and_the_id(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
const seen = [];
let open = null;
bind(input, idInput, list, {
  fetch: (url, opts) => {
    seen.push(JSON.parse(opts.body).query);
    return json({ artifacts: [{ id: 1, Title: 'Azul' }] });
  },
  setOpen: (value) => { open = value; },
});
input.value = 'Azul';
await input.fire('input');
input.value = '   ';
await input.fire('input');
input.value = '';
await input.fire('input');
input.value = ' \t ';
await input.fire('input');
return { seen, open, length: list.children.length, id: idInput.value };
JS);

        $this->assertSame(['seen' => ['Azul'], 'open' => false, 'length' => 0, 'id' => ''], $result);
    }

    public function test_the_collection_list_posts_the_trimmed_query_and_a_page_of_200(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
const seen = [];
let open = null;
bind(input, idInput, list, {
  fetch: (url, opts) => {
    seen.push({ url, method: opts.method, credentials: opts.credentials, body: JSON.parse(opts.body) });
    return json({ artifacts: [{ id: 5, Title: 'Azul' }] });
  },
  setOpen: (value) => { open = value; },
});
input.value = '  azul  ';
await input.fire('input');
return {
  seen,
  open,
  label: list.children[0].textContent,
  tab: list.children[0].tabIndex,
  id: idInput.value,
  typed: input.value,
};
JS);

        $this->assertSame([
            'seen' => [[
                'url' => 'https://api.test/artifacts.php',
                'method' => 'POST',
                'credentials' => 'include',
                'body' => ['query' => 'azul', 'per_page' => 200],
            ]],
            'open' => true,
            'label' => 'Azul',
            'tab' => 0,
            'id' => '5',
            'typed' => '  azul  ',
        ], $result);
    }

    public function test_the_list_shows_ten_rows_and_an_exact_title_past_them_sets_the_id(): void
    {
        $result = $this->run_(<<<'JS'
const artifacts = [];
for (let i = 1; i <= 10; i++) artifacts.push({ id: i, Title: 'Azul ' + i });
artifacts.push({ id: 42, Title: 'Azul' });
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
bind(input, idInput, list, {
  fetch: () => json({ artifacts }),
  setOpen: (value) => { open = value; },
});
input.value = 'Azul';
await input.fire('input');
return {
  length: list.children.length,
  first: list.children[0].textContent,
  last: list.children[9].textContent,
  id: idInput.value,
  typed: input.value,
  open,
};
JS);

        $this->assertSame([
            'length' => 10,
            'first' => 'Azul 1',
            'last' => 'Azul 10',
            'id' => '42',
            'typed' => 'Azul',
            'open' => true,
        ], $result);
    }

    public function test_collection_rows_stay_as_the_server_sent_them(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
bind(input, idInput, list, {
  fetch: () => json({ artifacts: [
    { id: 1, Title: 'Save 1000 today' },
    { id: 2, Title: 'Save 100% today' },
  ] }),
});
input.value = '100%';
await input.fire('input');
return {
  labels: list.children.map((li) => li.textContent),
  id: idInput.value,
  typed: input.value,
};
JS);

        $this->assertSame([
            'labels' => ['Save 1000 today', 'Save 100% today'],
            'id' => '',
            'typed' => '100%',
        ], $result);
    }

    public function test_click_enter_and_space_pick_and_escape_closes_without_clearing(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
bind(input, idInput, list, {
  items: [
    { id: 1, label: 'Ax' },
    { id: 2, label: 'Bx' },
    { id: 3, label: 'Cx' },
  ],
  setOpen: (value) => { open = value; },
});
input.value = 'x';
await input.fire('input');
list.children[0].fire('click', {});
const afterClick = { id: idInput.value, typed: input.value, open };
const enter = key('Enter');
list.children[1].fire('keydown', enter);
const afterEnter = {
  id: idInput.value,
  typed: input.value,
  open,
  prevented: enter.prevented,
  stopped: enter.stopped,
};
const space = key(' ');
list.children[2].fire('keydown', space);
const afterSpace = {
  id: idInput.value,
  typed: input.value,
  open,
  prevented: space.prevented,
  stopped: space.stopped,
};
const other = key('a');
list.children[0].fire('keydown', other);
const escape = key('Escape');
list.children[0].fire('keydown', escape);
const afterEscape = {
  open,
  id: idInput.value,
  typed: input.value,
  focused: input.focused,
  labels: list.children.map((li) => li.textContent),
  prevented: escape.prevented,
  stopped: escape.stopped,
};
input.fire('focus');
return {
  afterClick,
  afterEnter,
  afterSpace,
  other: [other.prevented, other.stopped],
  afterEscape,
  reopened: open,
};
JS);

        $this->assertSame([
            'afterClick' => ['id' => '1', 'typed' => 'Ax', 'open' => false],
            'afterEnter' => [
                'id' => '2',
                'typed' => 'Bx',
                'open' => false,
                'prevented' => true,
                'stopped' => true,
            ],
            'afterSpace' => [
                'id' => '3',
                'typed' => 'Cx',
                'open' => false,
                'prevented' => true,
                'stopped' => true,
            ],
            'other' => [false, false],
            'afterEscape' => [
                'open' => false,
                'id' => '3',
                'typed' => 'Cx',
                'focused' => 1,
                'labels' => ['Ax', 'Bx', 'Cx'],
                'prevented' => true,
                'stopped' => true,
            ],
            'reopened' => true,
        ], $result);
    }

    public function test_enter_in_the_search_and_arrow_keys_do_nothing(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
bind(input, idInput, list, {
  items: [
    { id: 1, label: 'Ax' },
    { id: 2, label: 'Bx' },
  ],
});
input.value = 'x';
await input.fire('input');
const enter = key('Enter');
input.fire('keydown', enter);
const downOnInput = key('ArrowDown');
input.fire('keydown', downOnInput);
const down = key('ArrowDown');
list.children[0].fire('keydown', down);
const up = key('ArrowUp');
list.children[0].fire('keydown', up);
return {
  id: idInput.value,
  typed: input.value,
  length: list.children.length,
  enter: [enter.prevented, enter.stopped],
  downOnInput: [downOnInput.prevented, downOnInput.stopped],
  down: [down.prevented, down.stopped],
  up: [up.prevented, up.stopped],
};
JS);

        $this->assertSame([
            'id' => '',
            'typed' => 'x',
            'length' => 2,
            'enter' => [false, false],
            'downOnInput' => [false, false],
            'down' => [false, false],
            'up' => [false, false],
        ], $result);
    }

    public function test_a_typed_title_sets_the_id_and_leaves_the_text(): void
    {
        $result = $this->run_(<<<'JS'
const items = [];
for (let i = 1; i <= 10; i++) items.push({ id: i, label: 'Azul ' + i });
items.push({ id: 42, label: 'Azul' });
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
bind(input, idInput, list, {
  items,
  setOpen: (value) => { open = value; },
});
input.value = '  aZuL  ';
await input.fire('input');
return {
  length: list.children.length,
  first: list.children[0].textContent,
  last: list.children[9].textContent,
  id: idInput.value,
  typed: input.value,
  open,
};
JS);

        $this->assertSame([
            'length' => 10,
            'first' => 'Azul 1',
            'last' => 'Azul 10',
            'id' => '42',
            'typed' => '  aZuL  ',
            'open' => true,
        ], $result);
    }

    public function test_any_other_text_clears_the_id_and_the_first_equal_label_wins(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
bind(input, idInput, list, {
  items: [
    { id: 1, label: 'Azul' },
    { id: 2, label: 'Azul' },
    { id: 3, label: 'Catan' },
  ],
});
input.value = 'AZUL';
await input.fire('input');
const exact = { id: idInput.value, typed: input.value };
list.children[0].fire('click', {});
const picked = { id: idInput.value, typed: input.value };
input.value = 'Azu';
await input.fire('input');
return {
  exact,
  picked,
  edited: {
    id: idInput.value,
    typed: input.value,
    labels: list.children.map((li) => li.textContent),
  },
};
JS);

        $this->assertSame([
            'exact' => ['id' => '1', 'typed' => 'AZUL'],
            'picked' => ['id' => '1', 'typed' => 'Azul'],
            'edited' => [
                'id' => '',
                'typed' => 'Azu',
                'labels' => ['Azul', 'Azul'],
            ],
        ], $result);
    }

    public function test_a_slower_search_does_not_replace_a_newer_list_or_id(): void
    {
        $result = $this->run_(<<<'JS'
let releaseFirst;
const first = new Promise((resolve) => { releaseFirst = resolve; });
let n = 0;
let cleared = null;
const input = el('input');
const idInput = el('input');
idInput.value = '5';
const list = el('ul');
let open = null;
bind(input, idInput, list, {
  fetch: () => {
    n += 1;
    if (n === 1) cleared = idInput.value;
    const body = n === 1 ? first : Promise.resolve({ artifacts: [{ id: 9, Title: 'Fran' }] });
    return body.then((data) => ({ json: async () => data }));
  },
  setOpen: (value) => { open = value; },
});
input.value = 'Old';
const older = input.fire('input');
input.value = 'Fran';
await input.fire('input');
releaseFirst({ artifacts: [{ id: 1, Title: 'Old' }] });
await older;
return {
  cleared,
  labels: list.children.map((li) => li.textContent),
  id: idInput.value,
  typed: input.value,
  open,
};
JS);

        $this->assertSame([
            'cleared' => '',
            'labels' => ['Fran'],
            'id' => '9',
            'typed' => 'Fran',
            'open' => true,
        ], $result);
    }

    public function test_a_slower_failure_does_not_replace_a_newer_list_or_id(): void
    {
        $result = $this->run_(<<<'JS'
let rejectFirst;
const first = new Promise((resolve, reject) => { rejectFirst = reject; });
let n = 0;
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
bind(input, idInput, list, {
  fetch: () => {
    n += 1;
    if (n === 1) return first;
    return json({ artifacts: [{ id: 9, Title: 'Fran' }] });
  },
  setOpen: (value) => { open = value; },
});
input.value = 'Old';
const older = input.fire('input');
input.value = 'Fran';
await input.fire('input');
rejectFirst(new Error('late'));
await older;
return {
  labels: list.children.map((li) => li.textContent),
  id: idInput.value,
  typed: input.value,
  open,
};
JS);

        $this->assertSame([
            'labels' => ['Fran'],
            'id' => '9',
            'typed' => 'Fran',
            'open' => true,
        ], $result);
    }

    public function test_a_failed_search_hides_the_list_and_does_not_throw(): void
    {
        $result = $this->run_(<<<'JS'
async function attempt(fetchFn) {
  const list = el('ul');
  let open = null;
  const input = el('input');
  bind(input, el('input'), list, {
    fetch: fetchFn,
    setOpen: (value) => { open = value; },
  });
  input.value = 'azul';
  await input.fire('input');
  return { length: list.children.length, open };
}
return {
  reject: await attempt(() => Promise.reject(new Error('offline'))),
  thrown: await attempt(() => { throw new Error('boom'); }),
  badJson: await attempt(() => Promise.resolve({ json: async () => { throw new Error('nope'); } })),
};
JS);

        $this->assertSame([
            'reject' => ['length' => 0, 'open' => false],
            'thrown' => ['length' => 0, 'open' => false],
            'badJson' => ['length' => 0, 'open' => false],
        ], $result);
    }

    public function test_only_boolean_false_sends_the_visitor_to_log_in(): void
    {
        $result = $this->run_(<<<'JS'
const cases = [
  { authenticated: false, artifacts: [{ id: 1, Title: 'Azul' }] },
  { authenticated: 'false', artifacts: [{ id: 1, Title: 'Azul' }] },
  { artifacts: [{ id: 2, Title: 'Catan' }] },
  { authenticated: 0, artifacts: [{ id: 3, Title: 'Dice' }] },
  { authenticated: true, artifacts: [{ id: 4, Title: 'Hive' }] },
];
const seen = [];
for (const data of cases) {
  globalThis.location.href = '';
  const list = el('ul');
  let open = null;
  const input = el('input');
  bind(input, el('input'), list, {
    fetch: () => json(data),
    setOpen: (value) => { open = value; },
  });
  input.value = data.artifacts[0].Title;
  await input.fire('input');
  seen.push({ href: globalThis.location.href, open, labels: list.children.map((li) => li.textContent) });
}
return seen;
JS);

        $this->assertSame([
            ['href' => '/login.php', 'open' => false, 'labels' => []],
            ['href' => '', 'open' => true, 'labels' => ['Azul']],
            ['href' => '', 'open' => true, 'labels' => ['Catan']],
            ['href' => '', 'open' => true, 'labels' => ['Dice']],
            ['href' => '', 'open' => true, 'labels' => ['Hive']],
        ], $result);
    }

    public function test_hide_keeps_the_list_and_focus_shows_it_without_searching(): void
    {
        $result = $this->run_(<<<'JS'
let calls = 0;
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
const bound = bind(input, idInput, list, {
  fetch: () => {
    calls += 1;
    return json({ artifacts: [{ id: 1, Title: 'Azul' }] });
  },
  setOpen: (value) => { open = value; },
});
input.value = 'Azul';
await input.fire('input');
bound.hide();
const hidden = {
  open,
  id: idInput.value,
  labels: list.children.map((li) => li.textContent),
  calls,
};
input.fire('focus');
return { hidden, shown: { open, calls } };
JS);

        $this->assertSame([
            'hidden' => ['open' => false, 'id' => '1', 'labels' => ['Azul'], 'calls' => 1],
            'shown' => ['open' => true, 'calls' => 1],
        ], $result);
    }

    public function test_a_click_outside_closes_the_list_without_clearing_it(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
const bound = bind(input, idInput, list, {
  items: [
    { id: 1, label: 'Azul' },
    { id: 2, label: 'Catan' },
  ],
  setOpen: (value) => { open = value; },
});
input.value = 'azul';
await input.fire('input');
bound.document.fire('click', { target: el('div') });
const afterOutside = {
  open,
  id: idInput.value,
  typed: input.value,
  labels: list.children.map((li) => li.textContent),
};
input.fire('focus');
const afterFocus = open;
bound.document.fire('click', { target: input });
const afterInput = open;
bound.document.fire('click', { target: list });
const afterList = open;
bound.document.fire('click', { target: list.children[0] });
const afterRow = open;
return { afterOutside, afterFocus, afterInput, afterList, afterRow };
JS);

        $this->assertSame([
            'afterOutside' => [
                'open' => false,
                'id' => '1',
                'typed' => 'azul',
                'labels' => ['Azul'],
            ],
            'afterFocus' => true,
            'afterInput' => true,
            'afterList' => true,
            'afterRow' => true,
        ], $result);
    }

    public function test_select_writes_the_id_and_name_and_beats_a_slower_search(): void
    {
        $result = $this->run_(<<<'JS'
const quietInput = el('input');
const quietId = el('input');
let quietCalls = 0;
let quietOpen = null;
const quiet = bind(quietInput, quietId, el('ul'), {
  fetch: () => { quietCalls += 1; return json({ artifacts: [] }); },
  setOpen: (value) => { quietOpen = value; },
});
quiet.select(4, 'Only');
const only = { calls: quietCalls, id: quietId.value, typed: quietInput.value, open: quietOpen };

let releaseFirst;
const first = new Promise((resolve) => { releaseFirst = resolve; });
let calls = 0;
const input = el('input');
const idInput = el('input');
const list = el('ul');
let open = null;
const bound = bind(input, idInput, list, {
  fetch: () => {
    calls += 1;
    return first.then((data) => ({ json: async () => data }));
  },
  setOpen: (value) => { open = value; },
});
input.value = 'Old';
const older = input.fire('input');
const during = idInput.value;
bound.select(9, 'New Game');
const afterSelect = { id: idInput.value, typed: input.value, open, calls };
releaseFirst({ artifacts: [{ id: 1, Title: 'Old' }] });
await older;
return {
  only,
  during,
  afterSelect,
  afterLate: {
    id: idInput.value,
    typed: input.value,
    labels: list.children.map((li) => li.textContent),
    open,
    calls,
  },
};
JS);

        $this->assertSame([
            'only' => ['calls' => 0, 'id' => '4', 'typed' => 'Only', 'open' => false],
            'during' => '',
            'afterSelect' => ['id' => '9', 'typed' => 'New Game', 'open' => false, 'calls' => 1],
            'afterLate' => [
                'id' => '9',
                'typed' => 'New Game',
                'labels' => [],
                'open' => false,
                'calls' => 1,
            ],
        ], $result);
    }

    public function test_the_catalog_matches_a_substring_and_keeps_percent_and_underscore_literal(): void
    {
        $result = $this->run_(<<<'JS'
const items = [
  { id: 1, label: 'Save 100% today' },
  { id: 2, label: 'Save 1000 today' },
  { id: 3, label: 'Save 100X today' },
  { id: 4, label: 'a_b' },
  { id: 5, label: 'axb' },
];
let calls = 0;
async function labelsFor(query) {
  const input = el('input');
  const list = el('ul');
  bind(input, el('input'), list, {
    items,
    fetch: () => { calls += 1; return json({ artifacts: [] }); },
  });
  input.value = query;
  await input.fire('input');
  return list.children.map((li) => li.textContent);
}
return {
  percent: await labelsFor('100%'),
  underscore: await labelsFor('_'),
  calls,
};
JS);

        $this->assertSame([
            'percent' => ['Save 100% today'],
            'underscore' => ['a_b'],
            'calls' => 0,
        ], $result);
    }

    public function test_pages_load_the_classic_script_and_drop_the_shared_search_box(): void
    {
        $recordNew = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-new.php');
        $editUser = (string) file_get_contents(PROJECT_PATH . '/ui/users/edit.php');
        $include = (string) file_get_contents(PROJECT_PATH . '/private/shared/user_interactions.php');
        $proposal = (string) file_get_contents(PROJECT_PATH . '/ui/proposals/edit.php');
        $proposalJs = (string) file_get_contents(PROJECT_PATH . '/ui/proposals/edit.js');
        $search = (string) file_get_contents(PROJECT_PATH . '/ui/uses/modules/searchArtifactsList.js');
        $create = (string) file_get_contents(PROJECT_PATH . '/ui/uses/modules/addNewEntity.js');
        $module = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/item-pick.js');
        $sw = (string) file_get_contents(PROJECT_PATH . '/ui/sw.js');
        $classic = '<script src="<?php echo url_for(\'/shared/js/item-pick.js\'); ?>"></script>';

        $this->assertStringContainsString($classic, $recordNew);
        $this->assert_before($recordNew, "url_for('/shared/js/item-pick.js')", 'modules/searchArtifactsList.js');
        $this->assert_before($recordNew, "url_for('/shared/js/item-pick.js')", 'modules/addNewEntity.js');
        $this->assertStringContainsString("SHARED_PATH . '/user_interactions.php'", $editUser);
        $this->assertStringNotContainsString('item-pick.js', $editUser);
        $this->assertStringContainsString($classic, $include);
        $this->assert_before($include, "url_for('/shared/js/item-pick.js')", 'globalThis.ItemPick');
        $this->assertStringContainsString($classic, $proposal);
        $this->assert_before($proposal, "url_for('/shared/js/item-pick.js')", '/proposals/edit.js');

        $this->assertStringContainsString('globalThis.ItemPick', $search);
        $this->assertStringContainsString('API_BASE + "/artifacts.php"', $search);
        $this->assertStringContainsString('#user0name', $search);
        $this->assertStringContainsString('.hide()', $search);
        $this->assertStringContainsString('export const itemPick', $search);
        $this->assertStringNotContainsString('userid', $search);
        $this->assertStringNotContainsString('toLowerCase', $search);
        $this->assertStringNotContainsString('require(', $search);
        $this->assertStringNotContainsString('item-pick.js', $search);

        $this->assertStringContainsString('itemPick.select(id, title)', $create);
        $this->assertStringNotContainsString('SearchTitleSubmission', $create);
        $this->assertStringNotContainsString('SearchTitles', $create);
        $this->assertStringNotContainsString('item-pick.js', $create);

        $this->assertStringContainsString('ItemPick', $include);
        $this->assertStringNotContainsString('SearchComponent', $include);
        $this->assertStringNotContainsString('search-component', $include);
        $this->assertStringNotContainsString('artifacts.php', $include);
        $this->assertStringNotContainsString('toLowerCase', $include);

        $this->assertStringContainsString('ItemPick', $proposalJs);
        $this->assertStringContainsString('function bindParticipantFilter()', $proposalJs);
        $this->assertStringContainsString('participant_search', $proposalJs);
        $this->assertStringNotContainsString('SearchComponent', $proposalJs);
        $this->assertStringNotContainsString('search-component', $proposalJs);
        $this->assertStringNotContainsString('ArrowDown', $proposalJs);
        $this->assertStringNotContainsString('ArrowUp', $proposalJs);
        $this->assertStringNotContainsString('chosen_item_name', $proposalJs);
        $this->assertStringNotContainsString('artifacts.php', $proposalJs);
        $this->assertStringNotContainsString('item-pick.js', $proposalJs);
        $this->assertDoesNotMatchRegularExpression('/chosenItemId\.value\s*=/', $proposalJs);

        $this->assertStringNotContainsString('export ', $module);
        $this->assertStringNotContainsString('api.keeplore.app', $module);
        $this->assertStringNotContainsString('keeplore.app', $module);

        $this->assertFileDoesNotExist(PROJECT_PATH . '/ui/shared/js/search-component.js');
        $this->assertStringNotContainsString('search-component.js', $sw);
        $this->assertStringNotContainsString('keeplore-v26', $sw);
        $this->assertMatchesRegularExpression("/const CACHE_NAME = 'keeplore-v\\d+';/", $sw);

        foreach ([
            '/ui/aversions/new.php',
            '/ui/aversions/edit.php',
            '/ui/shared/js/create-item-lookup.js',
            '/ui/shared/js/quick-record.js',
            '/ui/shared/js/person-pick.js',
            '/ui/uses/record-edit.php',
        ] as $path) {
            $source = (string) file_get_contents(PROJECT_PATH . $path);
            $this->assertStringNotContainsString('item-pick.js', $source, $path);
            $this->assertStringNotContainsString('ItemPick', $source, $path);
        }
    }

    private function assert_before(string $source, string $earlier, string $later): void
    {
        $earlyAt = strpos($source, $earlier);
        $lateAt = strpos($source, $later);
        $this->assertNotFalse($earlyAt, $earlier . ' is missing');
        $this->assertNotFalse($lateAt, $later . ' is missing');
        $this->assertLessThan($lateAt, $earlyAt, $earlier . ' should load before ' . $later);
    }

    /** @return mixed */
    private function run_(string $body)
    {
        $module = json_encode(PROJECT_PATH . '/ui/shared/js/item-pick.js');
        $script = <<<JS
const ItemPick = require({$module});
globalThis.location = { href: '' };

function el(tag) {
  const listeners = {};
  const node = {
    tagName: tag.toUpperCase(), children: [], style: {}, value: '', textContent: '',
    disabled: false, tabIndex: -1, focused: 0,
    classList: { add() {}, remove() {}, contains() { return false; } },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fire(type, event) {
      let result;
      (listeners[type] || []).forEach((fn) => { result = fn(event); });
      return result;
    },
    append(child) { node.children.push(child); },
    replaceChildren() { node.children = []; },
    contains(other) {
      if (other === node) return true;
      return node.children.some((child) => child === other || (typeof child.contains === 'function' && child.contains(other)));
    },
    focus() {
      node.focused += 1;
      node.fire('focus');
    },
  };
  return node;
}
function makeDocument() {
  const listeners = {};
  return {
    createElement: el,
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fire(type, event) {
      let result;
      (listeners[type] || []).forEach((fn) => { result = fn(event); });
      return result;
    },
  };
}
function key(k) {
  const event = {
    key: k, prevented: false, stopped: false,
    preventDefault() { event.prevented = true; },
    stopPropagation() { event.stopped = true; },
  };
  return event;
}
function json(data, ok) {
  return Promise.resolve({ ok: ok !== false, json: async () => data });
}
function bind(input, idInput, list, extra) {
  extra = extra || {};
  const documentNode = extra.document || makeDocument();
  const bound = ItemPick.bind(Object.assign({
    document: documentNode,
    input,
    idInput,
    list,
    url: 'https://api.test/artifacts.php',
    setOpen() {},
  }, extra));
  bound.document = documentNode;
  return bound;
}

(async () => {
{$body}
})().then((out) => {
  process.stdout.write(JSON.stringify(out));
}).catch((error) => {
  console.error(error && error.stack || error);
  process.exit(1);
});
JS;

        $cmd = 'node -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, $raw);

        return $decoded;
    }
}
