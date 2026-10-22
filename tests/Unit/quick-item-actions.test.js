const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const project = path.resolve(__dirname, '../..');
const flush = () => new Promise((resolve) => setImmediate(resolve));

// Only the DOM operations used by these page scripts. Each test gets a fresh
// document and browser context; requests stay deferred until the test responds.
function browser() {
  function matches(node, selector) {
    return selector.split(',').some((part) => {
      const pieces = part.trim().split(/\s+/);
      const last = pieces.pop();
      const match = last.match(/^(\w+)?(?:#([\w-]+))?(?:\.([\w-]+))?(?:\[([\w-]+)(?:="([^"]*)")?\])?$/);
      if (!match) throw new Error('Unsupported selector: ' + selector);
      if (match[1] && node.tagName !== match[1].toUpperCase()) return false;
      if (match[2] && node.id !== match[2]) return false;
      if (match[3] && !node.className.split(/\s+/).includes(match[3])) return false;
      if (match[4] && (node.getAttribute(match[4]) === null ||
          (match[5] !== undefined && node.getAttribute(match[4]) !== match[5]))) return false;
      let parent = node.parentNode;
      while (pieces.length) {
        const ancestor = pieces.pop();
        while (parent && !matches(parent, ancestor)) parent = parent.parentNode;
        if (!parent) return false;
        parent = parent.parentNode;
      }
      return true;
    });
  }

  function el(tag, attrs = {}, children = []) {
    const node = {
      tagName: tag.toUpperCase(), children: [], parentNode: null, dataset: {},
      attrs: {}, listeners: {}, className: '', id: '', value: '', disabled: false,
      style: {}, _text: '',
      setAttribute(name, value) {
        this.attrs[name] = String(value);
        if (['id', 'name', 'type', 'value', 'action'].includes(name)) this[name] = String(value);
      },
      getAttribute(name) { return this.attrs[name] ?? null; },
      removeAttribute(name) { delete this.attrs[name]; },
      addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
      dispatch(type, init = {}) {
        const event = { type, target: this, defaultPrevented: false,
          preventDefault() { this.defaultPrevented = true; }, ...init };
        for (let n = this; n; n = n.parentNode) {
          (n.listeners[type] || []).forEach((fn) => fn(event));
        }
        return event;
      },
      closest(selector) {
        for (let n = this; n; n = n.parentNode) if (matches(n, selector)) return n;
        return null;
      },
      contains(other) {
        for (let n = other; n; n = n.parentNode) if (n === this) return true;
        return false;
      },
      appendChild(child) {
        if (child.tagName === '#FRAGMENT') {
          child.children.slice().forEach((n) => this.appendChild(n));
          return child;
        }
        child.remove(); child.parentNode = this; this.children.push(child); return child;
      },
      remove() {
        if (this.parentNode) this.parentNode.children = this.parentNode.children.filter((n) => n !== this);
        this.parentNode = null;
      },
      querySelectorAll(selector) {
        return this.children.flatMap((child) =>
          [...(matches(child, selector) ? [child] : []), ...child.querySelectorAll(selector)]);
      },
      querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
      get textContent() { return this._text + this.children.map((n) => n.textContent).join(''); },
      set textContent(value) {
        this.children.slice().forEach((n) => n.remove()); this._text = String(value);
      },
      focus() { doc.activeElement = this; },
      blur() { doc.activeElement = null; },
    };
    node.classList = {
      add(...names) { node.className = [...new Set([...node.className.split(/\s+/), ...names])].join(' ').trim(); },
      remove(...names) { node.className = node.className.split(/\s+/).filter((n) => !names.includes(n)).join(' '); },
      contains(name) { return node.className.split(/\s+/).includes(name); },
      toggle(name, on) { if (on) this.add(name); else this.remove(name); },
    };
    for (const [name, value] of Object.entries(attrs)) {
      if (name === 'dataset') Object.assign(node.dataset, value);
      else if (name === 'className' || name === 'textContent') node[name] = value;
      else node.setAttribute(name, value);
    }
    children.forEach((child) => node.appendChild(child));
    return node;
  }

  const doc = el('document');
  doc.body = doc.appendChild(el('body'));
  doc.readyState = 'complete';
  doc.createElement = (tag) => el(tag);
  doc.createDocumentFragment = () => el('#fragment');
  doc.getElementById = (id) => doc.querySelector('#' + id);
  const requests = [], messages = [], confirmations = [];
  const context = vm.createContext({
    document: doc, console, setTimeout: () => 1, clearTimeout() {},
    alert: (message) => messages.push([message]),
    confirm: (message) => { confirmations.push(message); return context.confirmResult; },
    confirmResult: true,
    FormData: class {
      constructor(form) {
        this.entries = form.querySelectorAll('input').filter((n) => n.name && !n.disabled)
          .map((n) => [n.name, n.value]);
      }
      get(name) { return this.entries.find(([key]) => key === name)?.[1] ?? null; }
    },
    fetch: (url, options) => new Promise((resolve, reject) => requests.push({
      url, options, reject,
      respond(data, ok = true) { resolve({ ok, json: async () => data }); },
      invalidJSON() { resolve({ ok: true, json: async () => { throw new SyntaxError('Invalid JSON'); } }); },
    })),
  });
  context.window = context;
  function load(file) { vm.runInContext(fs.readFileSync(path.join(project, file), 'utf8'), context, { filename: file }); }
  return { el, doc, context, requests, messages, confirmations, load };
}

function keptRow(b, id = 11, kept = true) {
  return b.el('tr', {}, [b.el('td', { className: 'kept', dataset: { artifactId: String(id), kept: kept ? '1' : '0' } }, [
    b.el('form', { className: 'kept-toggle-form', action: '/set-tracked.php' }, [
      ...Object.entries({ artifact_id: String(id), artifact_name: 'Chess', csrf_token: 'tok', return_to: 'items', value: kept ? '0' : '1' })
        .map(([name, value]) => b.el('input', { name, value, type: 'hidden' })),
      b.el('button', { className: 'kept-toggle-btn', type: 'submit', 'aria-pressed': kept ? 'true' : 'false', textContent: kept ? 'Kept' : 'Keep' }),
    ]),
  ])]);
}

function assertKept(row, kept) {
  assert.equal(row.querySelector('td.kept').dataset.kept, kept ? '1' : '0');
  assert.equal(row.querySelector('button').textContent, kept ? 'Kept' : 'Keep');
  assert.equal(row.querySelector('button').getAttribute('aria-pressed'), kept ? 'true' : 'false');
  assert.equal(row.querySelector('input[name="value"]').value, kept ? '0' : '1');
}

test('delegated Kept submission preserves the form protocol and applies the returned state', async () => {
  const b = browser();
  b.load('ui/shared/js/quick-item-actions.js');
  const root = b.doc.body.appendChild(b.el('tbody'));
  const changes = [];
  b.context.QuickItemActions.bindKept(root, {
    toast: (m, k) => b.messages.push([m, k]), onChanged: (...args) => changes.push(args),
  });
  const row = root.appendChild(keptRow(b));
  assert.equal(row.querySelector('form').dispatch('submit').defaultPrevented, true);
  assert.equal(row.querySelector('button').disabled, true);
  assert.equal(row.querySelector('button').textContent, 'Kept');
  const { url, options } = b.requests[0];
  assert.equal(url, '/set-tracked.php');
  assert.equal(options.method, 'POST');
  assert.equal(options.credentials, 'include');
  assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(options.headers.Accept, 'application/json');
  assert.deepEqual(options.body.entries, [
    ['artifact_id', '11'], ['artifact_name', 'Chess'], ['csrf_token', 'tok'], ['return_to', 'items'], ['value', '0'],
  ]);
  // The returned state is authoritative, even when it differs from the request.
  b.requests[0].respond({ ok: true, is_kept: 1, message: 'Still kept' });
  await flush();
  assertKept(row, true);
  assert.equal(row.querySelector('button').disabled, false);
  assert.deepEqual(changes, [[11, true]]);
  assert.deepEqual(b.messages, [['Still kept', 'success']]);
});

test('pending Items are independent and a recreated form cannot submit the same Item twice', async () => {
  const b = browser();
  b.load('ui/shared/js/quick-item-actions.js');
  const root = b.doc.body.appendChild(b.el('tbody'));
  b.context.QuickItemActions.bindKept(root, { toast: (m, k) => b.messages.push([m, k]) });
  const original = root.appendChild(keptRow(b, 11));
  original.querySelector('form').dispatch('submit');
  original.remove();
  const replacement = root.appendChild(keptRow(b, 11));
  replacement.querySelector('form').dispatch('submit');
  const other = root.appendChild(keptRow(b, 12));
  other.querySelector('form').dispatch('submit');
  assert.equal(b.requests.length, 2);
  b.requests[0].respond({ ok: true, is_kept: 0 });
  b.requests[1].respond({ ok: true, is_kept: 1 });
  await flush();
  assertKept(original, false);
  assertKept(replacement, false);
  assertKept(other, true);
  replacement.querySelector('form').dispatch('submit');
  assert.equal(b.requests.length, 3, 'pending identity is released after completion');
  b.requests[2].respond({ ok: true, is_kept: 1 });
  await flush();
});

function interactBy() {
  const b = browser();
  const tbody = b.el('tbody');
  const table = b.doc.body.appendChild(b.el('table', { id: 'useBy', 'data-order': '[]' }, [tbody]));
  const count = b.doc.body.appendChild(b.el('span', { id: 'totalOverdue', textContent: '2' }));
  const rows = [11, 12, 13].map((id) => tbody.appendChild(b.el('tr', {}, [
    b.el('td', { className: 'overdue', textContent: id === 12 ? 'No' : 'Yes' }),
    b.el('td', { className: 'get-rid-of' }, ['get-rid-of', 'untrack'].map((action) =>
      b.el('form', { className: action + '-form', action: '/' + action + '.php' }, [
        ...Object.entries({ artifact_id: String(id), csrf_token: 'tok', return_to: 'useby', value: '0' })
          .map(([name, value]) => b.el('input', { name, value })),
        b.el('button', { type: 'submit', textContent: action === 'untrack' ? 'Remove' : 'Get Rid Of' }),
      ]))),
  ])));
  let dataTable;
  b.context.DataTable = class {
    constructor() { this.rows = rows.slice(); this.pageIndex = 0; this.removals = []; dataTable = this; this.draw(); }
    row(row) {
      return { remove: () => {
        this.removals.push(row);
        this.rows = this.rows.filter((n) => n !== row);
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
  b.load('ui/shared/js/record-use-submit.js');
  b.load('ui/shared/js/quick-item-actions.js');
  b.load('ui/artifacts/useby-table.js');
  return { ...b, table, tbody, rows, count, dataTable };
}

test('Interact By removes an initially detached row after pagination and captures it across a pending redraw', async () => {
  const b = interactBy();
  assert.equal(b.rows[2].parentNode, null, 'the third row was detached at binding');
  b.dataTable.page(1).draw();
  const form = b.rows[2].querySelector('.get-rid-of-form');
  assert.equal(form.dispatch('submit').defaultPrevented, true);
  assert.equal(form.querySelector('button').textContent, 'Removing…');
  b.rows[2].querySelector('.untrack-form').dispatch('submit');
  assert.equal(b.requests.length, 1, 'both removal actions share pending Item identity');
  b.dataTable.page(0).draw();
  b.requests[0].respond({ ok: true });
  await flush();
  assert.deepEqual(b.dataTable.removals, [b.rows[2]]);
  assert.equal(String(b.count.textContent), '1');
  assert.equal(form.querySelector('button').disabled, false);
  assert.deepEqual(b.messages, [['Marked to get rid of.']]);
  assert.equal(b.rows[0].parentNode, b.tbody);
});

test('Interact By handles a row entering after removal and leaves non-overdue counts alone', async () => {
  const b = interactBy();
  b.rows[0].querySelector('.untrack-form').dispatch('submit');
  b.requests[0].respond({ ok: true, message: 'Removed Chess' });
  await flush();
  assert.equal(b.rows[2].parentNode, b.tbody);
  b.rows[2].querySelector('.get-rid-of-form').dispatch('submit');
  b.requests[1].respond({ ok: true });
  await flush();
  b.rows[1].querySelector('.untrack-form').dispatch('submit');
  b.requests[2].respond({ ok: true });
  await flush();
  assert.equal(String(b.count.textContent), '0');
  assert.deepEqual(b.dataTable.removals, [b.rows[0], b.rows[2], b.rows[1]]);
  assert.deepEqual(b.messages, [['Removed Chess'], ['Marked to get rid of.'], ['Removed from tracked collection.']]);
});

for (const failure of ['http', 'body', 'json', 'network', 'throw']) {
  for (const action of failure === 'throw' ? ['kept'] : ['kept', 'removal']) {
    test(`${action} ${failure} failure retains prior state, recovers controls and allows retry`, async () => {
      const b = action === 'removal' ? interactBy() : browser();
      let root, row, form;
      const changes = [];
      if (action === 'kept') {
        b.load('ui/shared/js/quick-item-actions.js');
        root = b.doc.body.appendChild(b.el('tbody'));
        row = root.appendChild(keptRow(b));
        b.context.QuickItemActions.bindKept(root, {
          fetch: failure === 'throw' ? () => { throw new Error('Offline'); } : b.context.fetch,
          toast: (m, k) => b.messages.push([m, k]),
          onChanged: (...args) => changes.push(args),
        });
        form = row.querySelector('form');
      } else {
        root = b.tbody; row = b.rows[0]; form = row.querySelector('.untrack-form');
      }
      const button = form.querySelector('button');
      const label = button.textContent;
      form.dispatch('submit');
      form.dispatch('submit');
      if (failure !== 'throw') {
        assert.equal(b.requests.length, 1);
        if (failure === 'http') b.requests[0].respond({ ok: true, is_kept: 0, message: 'Denied' }, false);
        if (failure === 'body') b.requests[0].respond({ ok: false, is_kept: 0, message: 'Denied' });
        if (failure === 'json') b.requests[0].invalidJSON();
        if (failure === 'network') b.requests[0].reject(new Error('Offline'));
      }
      await flush();
      assert.equal(row.parentNode, root);
      assert.equal(button.disabled, false);
      assert.equal(button.textContent, label);
      assert.deepEqual(changes, []);
      if (action === 'kept') {
        assertKept(row, true);
        assert.equal(b.messages[0][1], 'error');
      } else {
        assert.deepEqual(b.dataTable.removals, []);
        assert.equal(b.count.textContent, '2');
      }
      assert.match(b.messages[0][0], /Denied|Invalid JSON|Offline/);
      if (failure !== 'throw') {
        form.dispatch('submit');
        assert.equal(b.requests.length, 2);
        b.requests[1].respond({ ok: true, is_kept: 0 });
        await flush();
      }
    });
  }
}

function toGetRidOf() {
  const b = browser();
  const table = b.doc.body.appendChild(b.el('table', { id: 'rid-of-table', className: 'list' }));
  b.load('ui/shared/js/quick-item-actions.js');
  const page = fs.readFileSync(path.join(project, 'ui/artifacts/to-get-rid-of.php'), 'utf8');
  const inline = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
  vm.runInContext(inline, b.context);
  return { ...b, table };
}

test('To get rid of confirms only unkeep, cancellation sends nothing and Restore submits ordinarily', async () => {
  const b = toGetRidOf();
  const row = b.table.appendChild(keptRow(b));
  const form = row.querySelector('form');
  b.context.confirmResult = false;
  assert.equal(form.dispatch('submit').defaultPrevented, true);
  assert.deepEqual(b.confirmations, ['Remove Chess from kept?']);
  assert.equal(b.requests.length, 0);
  assertKept(row, true);
  assert.equal(row.querySelector('button').disabled, false);
  b.context.confirmResult = true;
  form.dispatch('submit');
  b.requests[0].respond({ ok: true, is_kept: 0 });
  await flush();
  assertKept(row, false);
  form.dispatch('submit');
  assert.equal(b.confirmations.length, 2, 'Keep does not ask');
  b.requests[1].respond({ ok: true, is_kept: 1 });
  await flush();
  assertKept(row, true);
  const restore = b.table.appendChild(b.el('form', { action: '/restore.php' }));
  assert.equal(restore.dispatch('submit').defaultPrevented, false);
  assert.equal(b.requests.length, 2);
});

async function keptList(caller, isGuest = false) {
  const b = browser();
  const prefix = caller === 'items' ? 'items-list' : 'create-item-lookup';
  const search = b.doc.body.appendChild(b.el('input', { id: caller === 'items' ? 'items-search' : prefix + '-search', value: 'chess' }));
  const tbody = b.el('tbody', { id: prefix + '-body' });
  const keptHeader = b.el('th', { 'data-sort': 'is_kept', textContent: 'Kept' });
  const nameHeader = b.el('th', { 'data-sort': 'title', textContent: 'Name',
    id: caller === 'items' ? 'items-name-header' : prefix + '-name-header' });
  b.doc.body.appendChild(b.el('table', { id: caller === 'items' ? 'artifacts' : prefix }, [
    b.el('thead', {}, [b.el('tr', {}, [keptHeader, nameHeader])]), tbody,
  ]));
  const pager = b.doc.body.appendChild(b.el('div', { id: prefix + '-pager' }));
  b.doc.body.appendChild(b.el('div', { id: caller === 'items' ? 'items-toast' : prefix + '-toast' }));
  b.doc.body.appendChild(b.el('script', { id: prefix + '-config', textContent: JSON.stringify({
    dataUrl: '/items-data.php', keptToggleUrl: '/set-tracked.php', csrfToken: 'tok',
    itemUrlPrefix: '/edit.php?id=', pageLength: 1, columns: ['is_kept', 'title'], isGuest,
  }) }));
  b.load('ui/shared/js/list-table.js');
  b.load('ui/shared/js/quick-item-actions.js');
  if (caller === 'items') b.load('ui/shared/js/items-list.js');
  else if (caller === 'lookup-browser') b.load('ui/shared/js/create-item-lookup.js');
  else {
    // Keep the existing CommonJS injected-fetch entry point working without
    // a document at require time. DOM work begins only when bind is called.
    const lookup = require('../../ui/shared/js/create-item-lookup.js');
    const previousDocument = global.document;
    const previousFormData = global.FormData;
    global.document = b.doc;
    global.FormData = b.context.FormData;
    b.restore = () => { global.document = previousDocument; global.FormData = previousFormData; };
    lookup.bind(b.doc, b.context.fetch);
  }
  b.requests[0].respond({ items: [
    { id: 11, title: 'Chess', is_kept: true }, { id: 12, title: 'Chess set', is_kept: false },
  ] });
  await flush();
  return { ...b, search, tbody, pager, keptHeader, nameHeader };
}

for (const caller of ['items', 'lookup', 'lookup-browser']) {
  test(`${caller} uses shared pending identity and persists returned Kept state through pagination, search and sort`, async () => {
    const b = await keptList(caller);
    try {
      const original = b.tbody.children[0];
      assertKept(original, true);
      original.querySelector('form').dispatch('submit');
      b.search.dispatch('input');
      const replacement = b.tbody.children[0];
      assert.notEqual(original, replacement);
      replacement.querySelector('form').dispatch('submit');
      assert.equal(b.requests.length, 2, 'one data request and one action despite recreated form');
      b.requests[1].respond({ ok: true, is_kept: 0 });
      await flush();
      assertKept(b.tbody.children[0], false);
      b.pager.querySelectorAll('button').find((n) => n.textContent === 'Next').dispatch('click');
      b.pager.querySelectorAll('button').find((n) => n.textContent === 'Previous').dispatch('click');
      assertKept(b.tbody.children[0], false);
      b.search.value = 'Chess set'; b.search.dispatch('input');
      b.search.value = 'Chess'; b.search.dispatch('input');
      assertKept(b.tbody.children[0], false);
      b.keptHeader.dispatch('click');
      assertKept(b.tbody.children[0], false);
      assert.equal(b.tbody.children[0].querySelector('input[name="artifact_id"]').value, '11');
      b.tbody.children[0].querySelector('form').dispatch('submit');
      assert.equal(b.requests[2].options.body.get('value'), '1');
      b.requests[2].respond({ ok: true, is_kept: 1 });
      await flush();
      assertKept(b.tbody.children[0], true);
    } finally { if (b.restore) b.restore(); }
  });
}

test('guest Items render Kept text with no new write controls', async () => {
  const b = await keptList('items', true);
  assert.equal(b.tbody.querySelector('form'), null);
  assert.equal(b.tbody.querySelector('td.kept').textContent, 'yes');
  assert.equal(b.requests.length, 1);
});

test('unrelated forms inside a bound root keep their ordinary submission', () => {
  const b = browser();
  b.load('ui/shared/js/quick-item-actions.js');
  const root = b.doc.body;
  b.context.QuickItemActions.bindKept(root, { toast() {} });
  b.context.QuickItemActions.bindRemovals(root, { toast() {}, onRemoved() {} });
  const form = root.appendChild(b.el('form', { action: '/ordinary.php' }));
  assert.equal(form.dispatch('submit').defaultPrevented, false);
  assert.equal(b.requests.length, 0);
});
