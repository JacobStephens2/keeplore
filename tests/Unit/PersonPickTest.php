<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: PersonPick.bindSearch and PersonPick.createPerson
 * (ui/shared/js/person-pick.js).
 *
 * The person's name, the ten-result list, the search request, the
 * signed-out redirect, an empty query, a stale response, and inline
 * create. Row and popup tests keep only what those adapters add.
 */
class PersonPickTest extends TestCase
{
    public function test_a_blank_query_does_not_search_and_clears_the_list(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const list = el('ul');
const seen = [];
let open = null;
const bound = bind(input, list, {
  fetch: (url, opts) => {
    seen.push(JSON.parse(opts.body).query);
    return json({ users: [{ id: 1, FirstName: 'Ann', LastName: 'Lee' }] });
  },
  setOpen: (value) => { open = value; },
});
await bound.search('Ann');
await bound.search('   ');
await bound.search('');
input.value = ' \t ';
input.fire('input');
await flush();
return { seen, open, length: list.children.length };
JS);

        $this->assertSame(['seen' => ['Ann'], 'open' => false, 'length' => 0], $result);
    }

    public function test_search_posts_the_trimmed_query_and_the_userid(): void
    {
        $result = $this->run_(<<<'JS'
const input = el('input');
const list = el('ul');
const seen = [];
let open = null;
bind(input, list, {
  fetch: (url, opts) => {
    seen.push({ url, method: opts.method, credentials: opts.credentials, body: JSON.parse(opts.body) });
    return json({ users: [{ id: 5, FirstName: 'Ann', LastName: 'Lee' }] });
  },
  setOpen: (value) => { open = value; },
});
input.value = '  an  ';
input.fire('input');
await flush();
return { seen, open, label: list.children[0].textContent, tab: list.children[0].tabIndex };
JS);

        $this->assertSame([
            'seen' => [[
                'url' => 'https://api.test/users.php',
                'method' => 'POST',
                'credentials' => 'include',
                'body' => ['query' => 'an', 'userid' => '7'],
            ]],
            'open' => true,
            'label' => 'Ann Lee',
            'tab' => 0,
        ], $result);
    }

    public function test_the_list_shows_ten_names_and_prefers_full_name(): void
    {
        $result = $this->run_(<<<'JS'
const people = [
  { id: 1, FullName: 'Ada Lovelace', FirstName: 'Ada', LastName: 'Byron' },
  { id: 2, FirstName: 'Ann', LastName: 'Lee' },
  { id: 3, FirstName: 'Dan', LastName: '' },
  { id: 4, FirstName: '', LastName: 'Ng' },
];
for (let i = 5; i <= 12; i++) people.push({ id: i, FirstName: 'P' + i, LastName: 'Q' });
const list = el('ul');
await bind(el('input'), list, {
  fetch: () => json({ users: people }),
}).search('p');
return { length: list.children.length, labels: list.children.slice(0, 4).map((li) => li.textContent), last: list.children[9].textContent };
JS);

        $this->assertSame([
            'length' => 10,
            'labels' => ['Ada Lovelace', 'Ann Lee', 'Dan', 'Ng'],
            'last' => 'P10 Q',
        ], $result);
    }

    public function test_click_enter_and_space_pick_and_escape_does_not(): void
    {
        $result = $this->run_(<<<'JS'
const list = el('ul');
const picked = [];
let escaped = 0;
await bind(el('input'), list, {
  fetch: () => json({ users: [
    { id: 1, FirstName: 'Ann', LastName: 'Lee' },
    { id: 2, FirstName: 'Bea', LastName: 'Ray' },
    { id: 3, FirstName: 'Cy', LastName: 'Ng' },
  ] }),
  onPick: (person) => picked.push(person),
  onEscape: () => { escaped += 1; },
}).search('a');
list.children[0].fire('click', {});
const enter = key('Enter');
list.children[1].fire('keydown', enter);
const space = key(' ');
list.children[2].fire('keydown', space);
const escape = key('Escape');
list.children[0].fire('keydown', escape);
const other = key('a');
list.children[0].fire('keydown', other);
return {
  picked,
  escaped,
  enter: [enter.prevented, enter.stopped],
  space: [space.prevented, space.stopped],
  escape: [escape.prevented, escape.stopped],
  other: [other.prevented, other.stopped],
};
JS);

        $this->assertSame([
            'picked' => [
                ['id' => 1, 'name' => 'Ann Lee'],
                ['id' => 2, 'name' => 'Bea Ray'],
                ['id' => 3, 'name' => 'Cy Ng'],
            ],
            'escaped' => 1,
            'enter' => [true, true],
            'space' => [true, true],
            'escape' => [true, true],
            'other' => [false, false],
        ], $result);
    }

    public function test_a_slower_search_does_not_replace_a_newer_list(): void
    {
        $result = $this->run_(<<<'JS'
let releaseFirst;
const first = new Promise((resolve) => { releaseFirst = resolve; });
let n = 0;
const list = el('ul');
let open = null;
const bound = bind(el('input'), list, {
  fetch: () => {
    n += 1;
    const body = n === 1 ? first : Promise.resolve({ users: [{ id: 9, FirstName: 'Fran', LastName: 'Kay' }] });
    return body.then((data) => ({ json: async () => data }));
  },
  setOpen: (value) => { open = value; },
});
const older = bound.search('F');
await bound.search('Fr');
releaseFirst({ users: [{ id: 1, FirstName: 'Old', LastName: 'Name' }] });
await older;
return { labels: list.children.map((li) => li.textContent), open };
JS);

        $this->assertSame(['labels' => ['Fran Kay'], 'open' => true], $result);
    }

    public function test_a_slower_failure_does_not_replace_a_newer_list(): void
    {
        $result = $this->run_(<<<'JS'
let rejectFirst;
const first = new Promise((resolve, reject) => { rejectFirst = reject; });
let n = 0;
const list = el('ul');
let open = null;
const bound = bind(el('input'), list, {
  fetch: () => {
    n += 1;
    if (n === 1) return first;
    return json({ users: [{ id: 9, FirstName: 'Fran', LastName: 'Kay' }] });
  },
  setOpen: (value) => { open = value; },
});
const older = bound.search('F');
await bound.search('Fr');
rejectFirst(new Error('late'));
await older;
return { labels: list.children.map((li) => li.textContent), open };
JS);

        $this->assertSame(['labels' => ['Fran Kay'], 'open' => true], $result);
    }

    public function test_a_failed_search_hides_the_list_and_does_not_throw(): void
    {
        $result = $this->run_(<<<'JS'
let n = 0;
const list = el('ul');
let open = null;
const bound = bind(el('input'), list, {
  fetch: () => {
    n += 1;
    if (n === 1) return json({ users: [{ id: 1, FirstName: 'Ann', LastName: 'Lee' }] });
    return Promise.reject(new Error('offline'));
  },
  setOpen: (value) => { open = value; },
});
await bound.search('an');
await bound.search('annx');
return { length: list.children.length, open };
JS);

        $this->assertSame(['length' => 0, 'open' => false], $result);
    }

    public function test_only_boolean_false_sends_the_visitor_to_log_in(): void
    {
        $result = $this->run_(<<<'JS'
const cases = [
  { authenticated: false, users: [{ id: 1, FirstName: 'Ann', LastName: 'Lee' }] },
  { authenticated: 'false', users: [{ id: 1, FirstName: 'Ann', LastName: 'Lee' }] },
  { users: [{ id: 2, FirstName: 'Bea', LastName: 'Ray' }] },
  { authenticated: 0, users: [{ id: 3, FirstName: 'Cy', LastName: 'Ng' }] },
  { authenticated: true, users: [{ id: 4, FirstName: 'Di', LastName: 'Ko' }] },
];
const seen = [];
for (const data of cases) {
  globalThis.location.href = '';
  const list = el('ul');
  let open = null;
  await bind(el('input'), list, {
    fetch: () => json(data),
    setOpen: (value) => { open = value; },
  }).search('a');
  seen.push({ href: globalThis.location.href, open, labels: list.children.map((li) => li.textContent) });
}
return seen;
JS);

        $this->assertSame([
            ['href' => '/login.php', 'open' => false, 'labels' => []],
            ['href' => '', 'open' => true, 'labels' => ['Ann Lee']],
            ['href' => '', 'open' => true, 'labels' => ['Bea Ray']],
            ['href' => '', 'open' => true, 'labels' => ['Cy Ng']],
            ['href' => '', 'open' => true, 'labels' => ['Di Ko']],
        ], $result);
    }

    public function test_hide_does_not_clear_the_list(): void
    {
        $result = $this->run_(<<<'JS'
const list = el('ul');
let open = null;
const bound = bind(el('input'), list, {
  fetch: () => json({ users: [{ id: 1, FirstName: 'Ann', LastName: 'Lee' }] }),
  setOpen: (value) => { open = value; },
});
await bound.search('an');
bound.hide();
return { open, labels: list.children.map((li) => li.textContent) };
JS);

        $this->assertSame(['open' => false, 'labels' => ['Ann Lee']], $result);
    }

    public function test_create_sends_the_names_and_a_token_then_hands_back_the_person(): void
    {
        $result = $this->run_(<<<'JS'
const first = el('input');
const last = el('input');
const button = el('button');
const message = el('span');
first.value = ' Bea ';
last.value = ' Ray ';
let release;
const seen = [];
const handed = [];
const pending = PersonPick.createPerson({
  url: '/users/new.php',
  first, last, button, message,
  token: () => 'tok',
  fetch: (url, opts) => new Promise((resolve) => {
    seen.push({
      url,
      method: opts.method,
      credentials: opts.credentials,
      headers: opts.headers,
      body: opts.body.entries,
    });
    release = resolve;
  }),
  onSuccess: (id, fullName) => handed.push([id, fullName]),
});
const during = { disabled: button.disabled, message: message.textContent };
release({ ok: true, json: async () => ({ ok: true, id: 8, FullName: 'Bea Ray' }) });
await pending;
return { seen, during, handed, disabled: button.disabled, message: message.textContent };
JS);

        $this->assertSame([
            'seen' => [[
                'url' => '/users/new.php',
                'method' => 'POST',
                'credentials' => 'include',
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json',
                ],
                'body' => [['FirstName', 'Bea'], ['LastName', 'Ray'], ['csrf_token', 'tok']],
            ]],
            'during' => ['disabled' => true, 'message' => 'Creating…'],
            'handed' => [[8, 'Bea Ray']],
            'disabled' => false,
            'message' => 'Creating…',
        ], $result);
    }

    public function test_create_omits_an_empty_token_and_does_not_send_two_empty_names(): void
    {
        $result = $this->run_(<<<'JS'
async function send(token, firstName, lastName) {
  const first = el('input');
  const last = el('input');
  const button = el('button');
  const message = el('span');
  first.value = firstName;
  last.value = lastName;
  const seen = [];
  await PersonPick.createPerson({
    url: '/users/new.php',
    first, last, button, message,
    token: () => token,
    fetch: (url, opts) => {
      seen.push(opts.body.entries);
      return json({ ok: true, id: 1, FullName: 'X' });
    },
    onSuccess: () => {},
  });
  return { seen, message: message.textContent, disabled: button.disabled };
}
return {
  empty: await send('', 'Bea', 'Ray'),
  missing: await send(null, 'Bea', 'Ray'),
  blank: await send('tok', '  ', ' '),
};
JS);

        $this->assertSame([
            'empty' => [
                'seen' => [[['FirstName', 'Bea'], ['LastName', 'Ray']]],
                'message' => 'Creating…',
                'disabled' => false,
            ],
            'missing' => [
                'seen' => [[['FirstName', 'Bea'], ['LastName', 'Ray']]],
                'message' => 'Creating…',
                'disabled' => false,
            ],
            'blank' => [
                'seen' => [],
                'message' => 'Enter a name.',
                'disabled' => false,
            ],
        ], $result);
    }

    public function test_create_shows_the_server_message_or_the_fallback(): void
    {
        $result = $this->run_(<<<'JS'
async function attempt(responseOk, data) {
  const button = el('button');
  const message = el('span');
  const first = el('input');
  const last = el('input');
  first.value = 'Bea';
  last.value = 'Ray';
  let handed = false;
  await PersonPick.createPerson({
    url: '/users/new.php',
    first, last, button, message,
    token: () => 'tok',
    fetch: () => json(data, responseOk),
    onSuccess: () => { handed = true; },
  });
  return { message: message.textContent, disabled: button.disabled, handed };
}
return {
  server: await attempt(false, { ok: false, message: 'Name taken' }),
  fallback: await attempt(false, {}),
  notOk: await attempt(true, { message: '' }),
};
JS);

        $this->assertSame([
            'server' => ['message' => 'Name taken', 'disabled' => false, 'handed' => false],
            'fallback' => ['message' => 'Could not create person.', 'disabled' => false, 'handed' => false],
            'notOk' => ['message' => 'Could not create person.', 'disabled' => false, 'handed' => false],
        ], $result);
    }

    public function test_a_network_error_reenables_the_button(): void
    {
        $result = $this->run_(<<<'JS'
const button = el('button');
const message = el('span');
const first = el('input');
const last = el('input');
first.value = 'Bea';
last.value = 'Ray';
await PersonPick.createPerson({
  url: '/users/new.php',
  first, last, button, message,
  token: () => 'tok',
  fetch: () => Promise.reject(new Error('offline')),
  onSuccess: () => { throw new Error('should not hand back a person'); },
});
return { message: message.textContent, disabled: button.disabled };
JS);

        $this->assertSame(['message' => 'Error: offline', 'disabled' => false], $result);
    }

    public function test_pages_load_the_classic_script_before_the_callers(): void
    {
        $recordNew = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-new.php');
        $recordEdit = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-edit.php');
        $dashboard = (string) file_get_contents(PROJECT_PATH . '/ui/index.php');
        $useBy = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/useby.php');
        $popup = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/quick-record.js');
        $rows = (string) file_get_contents(PROJECT_PATH . '/ui/uses/modules/userRows.js');
        $module = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/person-pick.js');
        $classic = '<script src="<?php echo url_for(\'/shared/js/person-pick.js\'); ?>"></script>';

        $this->assertStringContainsString($classic, $recordNew);
        $this->assertStringContainsString($classic, $recordEdit);
        $this->assertStringContainsString($classic, $dashboard);
        $this->assertStringContainsString($classic, $useBy);
        $this->assert_before($recordNew, "url_for('/shared/js/person-pick.js')", 'modules/getUsers.js');
        $this->assert_before($recordNew, "url_for('/shared/js/person-pick.js')", 'modules/addNewUser.js');
        $this->assert_before($recordEdit, "url_for('/shared/js/person-pick.js')", 'modules/getUsers.js');
        $this->assert_before($dashboard, "url_for('/shared/js/person-pick.js')", "url_for('/shared/js/quick-record.js')");
        $this->assert_before($useBy, "url_for('/shared/js/person-pick.js')", "url_for('/shared/js/quick-record.js')");
        $this->assert_before($useBy, "url_for('/shared/js/quick-record.js')", "url_for('/artifacts/useby-table.js')");
        $this->assertStringContainsString(
            '<script src="<?php echo url_for(\'/shared/js/quick-record.js\'); ?>"></script>',
            $useBy
        );
        $this->assertStringContainsString('window.PersonPick', $popup);
        $this->assertStringContainsString("require('./person-pick.js')", $popup);
        $this->assertStringNotContainsString('require(', $rows);
        $this->assertStringNotContainsString("from \"./person-pick.js\"", $rows);
        $this->assertStringNotContainsString("from './person-pick.js'", $rows);
        $this->assertStringContainsString('globalThis.PersonPick', $rows);
        $this->assertStringContainsString('API_BASE + "/users.php"', $rows);
        $this->assertStringNotContainsString('api.keeplore.app', $module);
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
        $module = json_encode(PROJECT_PATH . '/ui/shared/js/person-pick.js');
        $script = <<<JS
const PersonPick = require({$module});
globalThis.location = { href: '' };

function el(tag) {
  const listeners = {};
  const node = {
    tagName: tag.toUpperCase(), children: [], style: {}, value: '', textContent: '',
    disabled: false, tabIndex: -1,
    classList: { add() {} },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fire(type, event) { (listeners[type] || []).forEach((fn) => fn(event)); },
    append(child) { node.children.push(child); },
    replaceChildren() { node.children = []; },
  };
  return node;
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
function flush() { return new Promise((resolve) => setTimeout(resolve, 0)); }
function bind(input, list, extra) {
  return PersonPick.bindSearch(Object.assign({
    document: { createElement: el },
    input, list,
    url: 'https://api.test/users.php',
    userid: '7',
    onPick() {},
    onEscape() {},
    setOpen() {},
  }, extra));
}
global.FormData = class {
  constructor() { this.entries = []; }
  append(name, value) { this.entries.push([name, value]); }
};

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
