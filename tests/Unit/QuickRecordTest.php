<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: QuickRecord.init(root, options).open(itemId, itemName, context).
 *
 * The Quick record popup on the dashboard and Interact By, including the
 * Interact By page's click and save flow. Tests run the real scripts under
 * node with stub DOM elements, paginated rows and fetch responses.
 */
class QuickRecordTest extends TestCase
{
    public function test_record_opens_the_popup_for_an_item_entering_from_the_next_page(): void
    {
        $result = $this->run_popup(<<<'JS'
for (let i = 0; i < 2; i++) {
  tbody.querySelector('td.record a').click();
  respondWith({ ok: true, is_overdue: false });
  form.dispatch('submit');
  await flush();
}
out.prevented = tbody.querySelector('td.record a').click().defaultPrevented;
out.remainingItems = tbody.children.map((row) => row.querySelector('td.name a').textContent);
JS, true);

        $this->assertTrue($result['prevented'], 'Record must open the popup rather than follow the link.');
        $this->assertFalse($result['hidden']);
        $this->assertSame('13', $result['itemId']);
        $this->assertSame('Azul', $result['itemName']);
        $this->assertSame(['Azul'], $result['remainingItems']);
    }

    public function test_record_on_the_second_page_opens_and_saves_the_current_item(): void
    {
        $result = $this->run_popup(<<<'JS'
pageTable.page(1).draw();
out.prevented = tbody.querySelector('td.record a span').click().defaultPrevented;
out.openedItem = byId('record-modal-artifact-id').value;
respondWith({ ok: true, is_overdue: false });
form.dispatch('submit');
await flush();
pageTable.page(0).draw();
out.remainingItems = tbody.children.map((row) => row.querySelector('td.name a').textContent);
out.overdueCount = doc.getElementById('totalOverdue').textContent;
JS, true);

        $this->assertTrue($result['prevented']);
        $this->assertSame('13', $result['openedItem']);
        $this->assertContains(['artifact[id]', '13'], $result['fetches'][0]['opts']['body']);
        $this->assertContains(['artifact[name]', 'Azul'], $result['fetches'][0]['opts']['body']);
        $this->assertSame(['Catan', 'Chess'], $result['remainingItems']);
        $this->assertSame('2', (string) $result['overdueCount']);
        $this->assertTrue($result['hidden']);
    }

    public function test_clicking_an_item_name_does_not_open_the_record_popup(): void
    {
        $result = $this->run_popup(<<<'JS'
out.prevented = tbody.querySelector('td.name a').click().defaultPrevented;
JS, true);

        $this->assertFalse($result['prevented']);
        $this->assertTrue($result['hidden']);
        $this->assertSame('', $result['itemId']);
    }

    public function test_opening_fills_in_the_item_and_resets_the_popup(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('12', 'Catan', 'row-12');
byId('record-modal-notes').value = 'old notes';
byId('record-modal-date').value = '2020-01-01';
await addPersonFromSearch('Ann', { id: 5, FirstName: 'Ann', LastName: 'Lee' });
byId('record-modal-user-search').value = 'leftover';
byId('record-modal-new-user-toggle').click();
popup.open('2807', 'Azul', 'row-2807');
JS);

        $this->assertFalse($result['hidden']);
        $this->assertSame('false', $result['ariaHidden']);
        $this->assertTrue($result['bodyModalOpen']);
        $this->assertSame('2807', $result['itemId']);
        $this->assertSame('Azul', $result['itemName']);
        $this->assertSame('Azul', $result['subtitle']);
        $this->assertSame('2026-10-04', $result['date']);
        $this->assertSame('', $result['notes']);
        $this->assertSame('Home', $result['setting']);
        $this->assertSame('/uses/record-new.php?artifact_id=2807', $result['fullFormHref']);
        $this->assertSame([['user[0][id]' => '3']], $result['people']);
        $this->assertSame('', $result['search']);
        $this->assertTrue($result['resultsHidden']);
        $this->assertSame('none', $result['newPersonFormDisplay']);
    }

    public function test_closes_on_backdrop_escape_and_cancel(): void
    {
        $result = $this->run_popup(<<<'JS'
const closed = [];
popup.open('1', 'Catan', null);
byId('record-modal-backdrop').click();
closed.push(root.hidden);
popup.open('1', 'Catan', null);
doc.body.dispatch('keydown', { key: 'Escape' });
closed.push(root.hidden);
popup.open('1', 'Catan', null);
byId('record-modal-cancel').click();
closed.push(root.hidden);
out.closed = closed;
JS);

        $this->assertSame([true, true, true], $result['closed']);
        $this->assertSame('true', $result['ariaHidden']);
        $this->assertFalse($result['bodyModalOpen']);
    }

    public function test_adding_a_person_skips_one_already_in_the_popup(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
await addPersonFromSearch('Ann', { id: 5, FirstName: 'Ann', LastName: 'Lee' });
await addPersonFromSearch('Ann', { id: 5, FirstName: 'Ann', LastName: 'Lee' });
await addPersonFromSearch('Me', { id: 3, FirstName: 'Jacob', LastName: 'S' });
JS);

        $this->assertSame([
            ['user[0][id]' => '3'],
            ['user[1][id]' => '5', 'user[1][name]' => 'Ann Lee'],
        ], $result['people']);
    }

    public function test_removing_a_person_drops_them_and_lets_them_be_added_again(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
await addPersonFromSearch('Ann', { id: 5, FirstName: 'Ann', LastName: 'Lee' });
const chip = byId('record-modal-users').children[1];
chip.children.find((c) => c.className === 'modal-user-remove').click();
out.afterRemove = people();
await addPersonFromSearch('Ann', { id: 5, FirstName: 'Ann', LastName: 'Lee' });
JS);

        $this->assertSame([['user[0][id]' => '3']], $result['afterRemove']);
        $this->assertCount(2, $result['people']);
    }

    public function test_the_people_search_posts_the_query(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
searchFor('an', [{ id: 5, FirstName: 'Ann', LastName: 'Lee' }, { id: 6, FirstName: 'Dan', LastName: '' }]);
await flush();
out.resultLabels = byId('record-modal-user-results').children.map((li) => li.textContent);
JS);

        $this->assertSame('https://api.test/users.php', $result['fetches'][0]['url']);
        $this->assertSame('POST', $result['fetches'][0]['opts']['method']);
        $this->assertSame(['query' => 'an', 'userid' => '7'], json_decode($result['fetches'][0]['opts']['body'], true));
        $this->assertSame(['Ann Lee', 'Dan'], $result['resultLabels']);
        $this->assertFalse($result['resultsHidden']);
    }

    public function test_new_person_is_created_and_added(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
byId('record-modal-new-user-toggle').click();
byId('record-modal-new-first').value = 'Bea';
byId('record-modal-new-last').value = 'Ray';
respondWith({ ok: true, id: 8, FullName: 'Bea Ray' });
byId('record-modal-new-create').click();
await flush();
JS);

        $this->assertSame('/users/new.php', $result['fetches'][0]['url']);
        $this->assertSame([['FirstName', 'Bea'], ['LastName', 'Ray'], ['csrf_token', 'tok']], $result['fetches'][0]['opts']['body']);
        $this->assertSame(['user[1][id]' => '8', 'user[1][name]' => 'Bea Ray'], $result['people'][1]);
        $this->assertSame('none', $result['newPersonFormDisplay']);
    }

    public function test_enter_saves_from_a_field(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
out.prevented = byId('record-modal-setting').dispatch('keydown', { key: 'Enter' }).defaultPrevented;
JS);

        $this->assertTrue($result['prevented']);
        $this->assertSame(1, $result['submits']);
    }

    public function test_enter_does_not_save_from_notes_or_the_new_person_form(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
out.notesPrevented = byId('record-modal-notes').dispatch('keydown', { key: 'Enter' }).defaultPrevented;
byId('record-modal-new-user-toggle').click();
byId('record-modal-new-first').value = '';
byId('record-modal-new-first').dispatch('keydown', { key: 'Enter' });
JS);

        $this->assertFalse($result['notesPrevented']);
        $this->assertSame(0, $result['submits']);
        $this->assertSame('Enter a name.', $result['newPersonMessage']);
    }

    public function test_enter_in_open_search_results_picks_the_top_result(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', null);
searchFor('an', [{ id: 5, FirstName: 'Ann', LastName: 'Lee' }, { id: 6, FirstName: 'Dan', LastName: '' }]);
await flush();
out.prevented = byId('record-modal-user-search').dispatch('keydown', { key: 'Enter' }).defaultPrevented;
JS);

        $this->assertTrue($result['prevented']);
        $this->assertSame(0, $result['submits']);
        $this->assertSame(['user[1][id]' => '5', 'user[1][name]' => 'Ann Lee'], $result['people'][1]);
        $this->assertTrue($result['resultsHidden']);
    }

    public function test_successful_submit_calls_on_recorded_with_the_context_and_closes(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', 'row-1');
respondWith({ ok: true, message: 'Recorded', is_overdue: false });
byId('record-modal-setting').dispatch('keydown', { key: 'Enter' });
await flush();
JS);

        $this->assertSame('/uses/record-new.php', $result['fetches'][0]['url']);
        $this->assertSame('XMLHttpRequest', $result['fetches'][0]['opts']['headers']['X-Requested-With']);
        $this->assertSame([[['ok' => true, 'message' => 'Recorded', 'is_overdue' => false], 'row-1']], $result['recorded']);
        $this->assertSame([['Recorded', 'success']], $result['toasts']);
        $this->assertTrue($result['hidden']);
    }

    public function test_closing_while_saving_still_reports_the_saved_items_context(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', 'row-1');
respondWith({ ok: true, message: 'Recorded', is_overdue: true });
byId('record-modal-form').requestSubmit();
byId('record-modal-cancel').click();
popup.open('2', 'Azul', 'row-2');
await flush();
JS);

        $this->assertSame('row-1', $result['recorded'][0][1]);
        $this->assertFalse($result['hidden']);
        $this->assertSame('2', $result['itemId']);
    }

    public function test_failed_submit_keeps_the_popup_open(): void
    {
        $result = $this->run_popup(<<<'JS'
popup.open('1', 'Catan', 'row-1');
respondWith({ ok: false, message: 'Please choose an item.' });
byId('record-modal-form').requestSubmit();
await flush();
JS);

        $this->assertSame([], $result['recorded']);
        $this->assertSame([['Please choose an item.', 'error']], $result['toasts']);
        $this->assertFalse($result['hidden']);
    }

    public function test_dashboard_and_use_by_open_the_shared_popup(): void
    {
        $pages = [
            PROJECT_PATH . '/ui/index.php',
            PROJECT_PATH . '/ui/artifacts/useby.php',
        ];
        foreach ($pages as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString("SHARED_PATH . '/quick_record_popup.php'", $source, $path);
            $this->assertStringContainsString('quick-record.js', $source, $path);
            $this->assertStringContainsString('QuickRecord.init', $source, $path);
            $this->assertStringNotContainsString('id="record-modal-form"', $source, $path);
            $this->assertStringNotContainsString('todayLocal', $source, $path);
            $this->assertStringNotContainsString('recordModalResetUsers', $source, $path);
        }
    }

    /**
     * Runs $scenario against a stub popup and returns the popup's state.
     *
     * @return array<string, mixed>
     */
    private function run_popup(string $scenario, bool $useByPage = false): array
    {
        $submitModule = json_encode(PROJECT_PATH . '/ui/shared/js/record-use-submit.js');
        $module = json_encode(PROJECT_PATH . '/ui/shared/js/quick-record.js');
        $page = json_encode(PROJECT_PATH . '/ui/artifacts/useby.php');
        $useByPageJson = json_encode($useByPage);
        $script = <<<JS
require({$submitModule});
const QuickRecord = require({$module});

function matches(el, sel) {
  if (sel.includes(' ') && !sel.includes('[')) {
    const parts = sel.split(' ');
    if (!matches(el, parts.pop())) return false;
    let parent = el.parent;
    while (parts.length) {
      const part = parts.pop();
      while (parent && !matches(parent, part)) parent = parent.parent;
      if (!parent) return false;
      parent = parent.parent;
    }
    return true;
  }
  const simple = sel.match(/^(\\w+)?(?:#([\\w-]+))?(?:\\.([\\w-]+))?$/);
  if (simple) return (!simple[1] || el.tagName === simple[1].toUpperCase())
    && (!simple[2] || el.id === simple[2])
    && (!simple[3] || (el.className || '').split(' ').includes(simple[3]));
  let m = sel.match(/^\\[([\\w-]+)\\]$/);
  if (m) return el.getAttribute(m[1]) !== null;
  m = sel.match(/^(\\w+)\\[name="(.+)"\\]$/);
  if (m) return el.tagName === m[1].toUpperCase() && el.name === m[2];
  throw new Error('Unsupported selector ' + sel);
}

function find(node, sel) {
  const found = [];
  (function walk(n) {
    n.children.forEach((c) => { if (matches(c, sel)) found.push(c); walk(c); });
  })(node);
  return found;
}

function el(tag, props, children) {
  const node = {
    tagName: tag.toUpperCase(), id: '', className: '', name: '', type: '', value: '',
    textContent: '', hidden: false, disabled: false, style: {}, attrs: {},
    parent: null, children: [], listeners: {},
    classList: {
      set: new Set(),
      add(c) { this.set.add(c); }, remove(c) { this.set.delete(c); }, contains(c) { return this.set.has(c); },
    },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    dispatch(type, init) {
      const event = Object.assign({
        type, target: this, defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
      }, init || {});
      for (let n = this; n; n = n.parent) {
        (n.listeners[type] || []).forEach((fn) => fn(event));
      }
      return event;
    },
    click() { return this.dispatch('click'); },
    closest(sel) {
      for (let n = this; n; n = n.parent) if (matches(n, sel)) return n;
      return null;
    },
    focus() { doc.activeElement = this; },
    appendChild(child) { child.remove(); child.parent = this; this.children.push(child); return child; },
    append(...cs) { cs.forEach((c) => this.appendChild(c)); },
    remove() {
      if (!this.parent) return;
      this.parent.children = this.parent.children.filter((c) => c !== this);
      this.parent = null;
    },
    contains(other) {
      for (let n = other; n; n = n.parent) if (n === this) return true;
      return false;
    },
    get firstElementChild() { return this.children[0] || null; },
    get innerText() { return this.textContent; },
    set innerText(value) { this.textContent = String(value); },
    set innerHTML(v) { this.children.forEach((c) => { c.parent = null; }); this.children = []; },
    querySelector(sel) { return find(this, sel)[0] || null; },
    querySelectorAll(sel) { return find(this, sel); },
  };
  const { attrs, ...rest } = props || {};
  Object.assign(node, rest);
  Object.assign(node.attrs, attrs || {});
  (children || []).forEach((c) => node.appendChild(c));
  return node;
}

const doc = el('#document');
doc.body = doc.appendChild(el('body'));
doc.createElement = (tag) => el(tag);
doc.getElementById = (id) => doc.querySelector('#' + id);

const closer = { 'data-modal-close': '' };
const form = el('form', { id: 'record-modal-form', action: '/uses/record-new.php', attrs: { action: '/uses/record-new.php' } }, [
  el('input', { name: 'csrf_token', value: 'tok' }),
  el('input', { id: 'record-modal-artifact-id', name: 'artifact[id]' }),
  el('input', { id: 'record-modal-artifact-name', name: 'artifact[name]' }),
  el('div', { id: 'record-modal-users' }, [
    el('div', { className: 'modal-user-chip' }, [
      el('input', { id: 'record-modal-owner-id', name: 'user[0][id]', value: '3' }),
    ]),
  ]),
  el('div', { id: 'record-modal-user-search-wrap' }, [
    el('input', { id: 'record-modal-user-search' }),
    el('ul', { id: 'record-modal-user-results', hidden: true }),
  ]),
  el('button', { id: 'record-modal-new-user-toggle' }),
  el('div', { id: 'record-modal-new-user-form', style: { display: 'none' } }, [
    el('input', { id: 'record-modal-new-first' }),
    el('input', { id: 'record-modal-new-last' }),
    el('button', { id: 'record-modal-new-create' }),
    el('button', { id: 'record-modal-new-cancel' }),
    el('span', { id: 'record-modal-new-msg' }),
  ]),
  el('input', { id: 'record-modal-date' }),
  el('input', { id: 'record-modal-setting', value: 'Home' }),
  el('textarea', { id: 'record-modal-notes' }),
  el('a', { id: 'record-modal-fullform-link' }),
  el('button', { id: 'record-modal-cancel', attrs: closer }),
  el('button', { className: 'modal-save', type: 'submit', textContent: 'Save' }),
]);
let submits = 0;
form.requestSubmit = () => { submits++; form.dispatch('submit'); };
const root = el('div', {
  id: 'record-modal', hidden: true,
  attrs: {
    'data-people-search-url': 'https://api.test/users.php',
    'data-new-person-url': '/users/new.php',
    'data-user-id': '7',
  },
}, [
  el('div', { id: 'record-modal-backdrop', attrs: closer }),
  el('p', { id: 'record-modal-artifact' }),
  form,
]);
doc.body.appendChild(root);

function byId(id) { return id === 'record-modal' ? root : root.querySelector('#' + id); }

global.FormData = class {
  constructor(form) {
    this.entries = form ? form.querySelectorAll('input').filter((input) => input.name)
      .map((input) => [input.name, input.value]) : [];
  }
  append(k, v) { this.entries.push([k, v]); }
};

const fetches = [];
const responses = [];
function respondWith(body) { responses.push(body); }
const fetchFn = (url, opts) => {
  const body = opts.body instanceof FormData ? opts.body.entries : opts.body;
  fetches.push({ url, opts: Object.assign({}, opts, { body }) });
  const data = responses.shift() || { users: [] };
  return Promise.resolve({ ok: data.ok !== false, json: () => Promise.resolve(data) });
};
function flush() { return new Promise((resolve) => setTimeout(resolve, 0)); }

function searchFor(query, users) {
  respondWith({ users });
  byId('record-modal-user-search').value = query;
  byId('record-modal-user-search').dispatch('input');
}
function addPersonFromSearch(query, user) {
  searchFor(query, [user]);
  return flush().then(() => byId('record-modal-user-results').firstElementChild.click());
}

function people() {
  return byId('record-modal-users').children.map((chip) => {
    const fields = {};
    chip.children.filter((c) => c.tagName === 'INPUT').forEach((c) => { fields[c.name] = c.value; });
    return fields;
  });
}

const toasts = [];
const recorded = [];
const popup = {$useByPageJson} ? null : QuickRecord.init(root, {
  document: doc,
  fetch: fetchFn,
  today: () => '2026-10-04',
  toast: (m, k) => toasts.push([m, k]),
  onRecorded: (data, context) => recorded.push([data, context]),
});
let tbody;
let pageTable;
if ({$useByPageJson}) {
  tbody = el('tbody');
  doc.body.appendChild(el('table', { id: 'useBy' }, [tbody]));
  doc.body.appendChild(el('span', { id: 'totalOverdue' }));
  for (const [id, name] of [[11, 'Catan'], [12, 'Chess'], [13, 'Azul']]) {
    tbody.appendChild(el('tr', {}, [
      el('td', { className: 'name' }, [el('a', { textContent: name })]),
      el('td', { className: 'record' }, [el('a', { attrs: { href: '/uses/record-new?artifact_id=' + id } }, [el('span', { textContent: 'Record' })])]),
      el('td', { className: 'overdue', textContent: 'Yes' }),
    ]));
  }
  // Model DataTable's DOM boundary: only a page's rows stay attached;
  // removal redraws that page using the next rows from its stored data.
  global.DataTable = class {
    constructor() {
      this.rows = tbody.children.slice();
      this.pageIndex = 0;
      pageTable = this;
      this.draw();
    }
    row(row) {
      return { remove: () => {
        this.rows = this.rows.filter((item) => item !== row);
        return this;
      } };
    }
    page(index) { this.pageIndex = index; return this; }
    draw() {
      tbody.children.slice().forEach((row) => row.remove());
      this.rows.slice(this.pageIndex * 2, this.pageIndex * 2 + 2).forEach((row) => tbody.appendChild(row));
      return this;
    }
  };
  global.document = doc;
  global.fetch = fetchFn;
  global.window = { RecordUseSubmit: require({$submitModule}), QuickRecord };
  global.RecordUseSubmit = window.RecordUseSubmit;
  global.QuickRecord = QuickRecord;
  global.alert = (message) => toasts.push(message);
  const source = require('node:fs').readFileSync({$page}, 'utf8');
  const scripts = Array.from(source.matchAll(/<script>([\\s\\S]*?)<\\/script>/g));
  // Run the page's inline JS, supplying the two server-rendered values.
  const pageScript = scripts.at(-1)[1]
    .replace(/<\\?php echo [$]total_overdue; \\?>/g, '3')
    .replace(/<\\?php echo json_encode\\([\\s\\S]*?\\); \\?>/g, '[]');
  new Function(pageScript)();
}
const out = {};

(async () => {
{$scenario}
  await flush();
  process.stdout.write(JSON.stringify(Object.assign({
    hidden: root.hidden,
    ariaHidden: root.getAttribute('aria-hidden'),
    bodyModalOpen: doc.body.classList.contains('modal-open'),
    itemId: byId('record-modal-artifact-id').value,
    itemName: byId('record-modal-artifact-name').value,
    subtitle: byId('record-modal-artifact').textContent,
    date: byId('record-modal-date').value,
    notes: byId('record-modal-notes').value,
    setting: byId('record-modal-setting').value,
    fullFormHref: byId('record-modal-fullform-link').href,
    people: people(),
    search: byId('record-modal-user-search').value,
    resultsHidden: byId('record-modal-user-results').hidden,
    newPersonFormDisplay: byId('record-modal-new-user-form').style.display,
    newPersonMessage: byId('record-modal-new-msg').textContent,
    fetches, toasts, recorded, submits,
  }, out)));
})().catch((e) => { console.error(e && e.stack || e); process.exit(1); });
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
