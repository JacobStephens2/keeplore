<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ui/uses/modules/addNewUser.js.
 *
 * Creating a person on Record Use adds a row named with FullName, and the
 * CSRF token comes from the record form rather than the first token on
 * the page.
 */
class AddNewUserTest extends TestCase
{
    public function test_creating_a_person_adds_a_row_with_the_record_form_token(): void
    {
        $result = $this->run_(<<<'JS'
byId('newInteractorFirst').value = 'Bea';
byId('newInteractorLast').value = 'Ray';
byId('createInteractor').fire('click', { preventDefault() {} });
await flush();
return {
  url: fetched.url,
  body: fetched.body,
  name: byId('user1name').value,
  id: byId('user1id').value,
  formDisplay: byId('newInteractorForm').style.display,
};
JS);

        $this->assertSame('/users/new.php', $result['url']);
        $this->assertSame([
            ['FirstName', 'Bea'],
            ['LastName', 'Ray'],
            ['csrf_token', 'tok'],
        ], $result['body']);
        $this->assertSame('Bea Ray', $result['name']);
        $this->assertSame('8', $result['id']);
        $this->assertSame('none', $result['formDisplay']);
    }

    /** @return array<string, mixed> */
    private function run_(string $body): array
    {
        $personPick = json_encode('file://' . PROJECT_PATH . '/ui/shared/js/person-pick.js');
        $module = json_encode('file://' . PROJECT_PATH . '/ui/uses/modules/addNewUser.js');
        $script = <<<JS
function matches(node, sel) {
  if (sel.startsWith('#')) return node.id === sel.slice(1);
  if (sel === 'input.user') return node.tagName === 'INPUT' && /\buser\b/.test(node.className || '');
  if (sel === 'section#users') return node.tagName === 'SECTION' && node.id === 'users';
  if (sel === 'form') return node.tagName === 'FORM';
  if (sel === 'input[name="csrf_token"]') return node.tagName === 'INPUT' && node.name === 'csrf_token';
  if (sel === 'meta[name="keeplore-api-origin"]') return false;
  throw new Error('Unsupported selector ' + sel);
}
function walk(node, visit) {
  (node.children || []).forEach((child) => { visit(child); walk(child, visit); });
}
function query(root, sel) {
  let found = null;
  walk(root, (node) => { if (!found && matches(node, sel)) found = node; });
  return found;
}
function queryAll(root, sel) {
  const found = [];
  walk(root, (node) => { if (matches(node, sel)) found.push(node); });
  return found;
}
function el(tag) {
  const listeners = {};
  const node = {
    tagName: tag.toUpperCase(), children: [], attrs: {}, dataset: {}, style: {},
    className: '', id: '', name: '', value: '', textContent: '', disabled: false, parent: null,
    classList: { add(c) { node.className = (node.className + ' ' + c).trim(); } },
    setAttribute(k, v) {
      node.attrs[k] = String(v);
      if (k === 'id') node.id = String(v);
      if (k === 'name') node.name = String(v);
      if (k.startsWith('data-')) node.dataset[k.slice(5).replace(/-(\w)/g, (_, c) => c.toUpperCase())] = String(v);
    },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(node.attrs, k) ? node.attrs[k] : null; },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fire(type, event) { (listeners[type] || []).forEach((fn) => fn(event)); },
    append(...kids) { kids.forEach((kid) => node.appendChild(kid)); },
    appendChild(kid) {
      if (kid.parent) kid.parent.children = kid.parent.children.filter((child) => child !== kid);
      kid.parent = node;
      node.children.push(kid);
      return kid;
    },
    replaceChildren() { node.children = []; },
    closest(sel) {
      for (let current = node; current; current = current.parent) {
        if (matches(current, sel)) return current;
      }
      return null;
    },
    querySelector(sel) { return query(node, sel); },
    querySelectorAll(sel) { return queryAll(node, sel); },
    focus() { doc.activeElement = node; },
    contains(other) {
      if (other === node) return true;
      return node.children.some((child) => child === other || child.contains(other));
    },
  };
  return node;
}
function byId(id) { return doc.querySelector('#' + id); }
const doc = el('div');
doc.activeElement = null;
doc.createElement = el;
doc.querySelector = (sel) => query(doc, sel);
doc.querySelectorAll = (sel) => queryAll(doc, sel);

const decoy = el('form');
const otherToken = el('input');
otherToken.name = 'csrf_token';
otherToken.value = 'other-page';
decoy.append(otherToken);

const form = el('form');
const token = el('input');
token.name = 'csrf_token';
token.value = 'tok';
const show = el('button');
show.setAttribute('id', 'showNewInteractor');
const wrap = el('div');
wrap.setAttribute('id', 'newInteractorForm');
const first = el('input');
first.setAttribute('id', 'newInteractorFirst');
const last = el('input');
last.setAttribute('id', 'newInteractorLast');
const create = el('button');
create.setAttribute('id', 'createInteractor');
const cancel = el('button');
cancel.setAttribute('id', 'cancelNewInteractor');
const message = el('span');
message.setAttribute('id', 'newInteractorMsg');
wrap.append(first, last, create, cancel, message);
const section = el('section');
section.setAttribute('id', 'users');
const user0 = el('input');
user0.setAttribute('id', 'user0name');
user0.classList.add('user');
user0.setAttribute('data-userid', '7');
section.append(user0);
form.append(token, show, wrap, section);
doc.append(decoy, form);
globalThis.document = doc;

globalThis.FormData = class {
  constructor() { this.entries = []; }
  append(name, value) { this.entries.push([name, value]); }
};
let fetched = null;
globalThis.fetch = async (url, opts) => {
  fetched = { url, body: opts.body.entries };
  return { ok: true, json: async () => ({ ok: true, id: 8, FullName: 'Bea Ray' }) };
};
function flush() { return new Promise((resolve) => setTimeout(resolve, 0)); }

await import({$personPick});
await import({$module});

(async () => {
{$body}
})().then((out) => {
  process.stdout.write(JSON.stringify(out));
}).catch((error) => {
  console.error(error && error.stack || error);
  process.exit(1);
});
JS;

        $cmd = 'node --input-type=module -e ' . escapeshellarg($script) . ' 2>&1';
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
