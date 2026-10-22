<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ui/uses/modules/userRows.js.
 *
 * Every person row on Record Use and Edit Use (server-rendered or added
 * with +) is wired by wireUserRow. Person search itself lives in
 * person-pick.js. These tests keep the row: filling the picked person,
 * hiding when focus leaves, Escape in the field, and the remove button.
 * Tests drive it through a small fake DOM and an injected fetch.
 */
class UserRowsTest extends TestCase
{
    public function test_next_index_follows_the_rows_in_order(): void
    {
        $this->assertSame(3, $this->run_('return nextUserIndex(rowsDoc(["user0name", "user1name", "user2name"]))'));
    }

    public function test_next_index_skips_past_a_removed_row(): void
    {
        $this->assertSame(3, $this->run_('return nextUserIndex(rowsDoc(["user0name", "user2name"]))'));
    }

    public function test_next_index_is_one_with_only_the_first_row(): void
    {
        $this->assertSame(1, $this->run_('return nextUserIndex(rowsDoc(["user0name"]))'));
    }

    public function test_picking_from_a_row_fills_it_and_returns_focus(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const parts = rowParts(row);
await wireUserRow(doc, row, { fetch: fetchPeople(people(2)) }).search('Fi');
const resultsShown = parts.results.style.display;
parts.list.children[1].fire('keydown', key('Enter'));
return {
  resultsShown,
  id: parts.id.value,
  name: parts.name.value,
  resultsAfter: parts.results.style.display,
  focused: doc.activeElement === parts.name,
};
JS);

        $this->assertSame([
            'resultsShown' => 'block',
            'id' => '1',
            'name' => 'First1 Last1',
            'resultsAfter' => 'none',
            'focused' => true,
        ], $result);
    }

    public function test_built_row_names_its_fields_by_index(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 4, '7');
const parts = rowParts(row);
return [row.className, parts.name.id, parts.name.getAttribute('name'), parts.id.getAttribute('name'), parts.name.dataset.userid, parts.remove.textContent];
JS);

        $this->assertSame(['person-row', 'user4name', 'user[4][name]', 'user[4][id]', '7', '-'], $result);
    }

    public function test_remove_button_drops_the_row(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
let removed = false;
row.remove = () => { removed = true; };
wireUserRow(doc, row);
rowParts(row).remove.fire('click', key(''));
return removed;
JS);

        $this->assertTrue($result);
    }

    public function test_a_server_placeholder_result_does_not_open_an_empty_box(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 0, '7');
rowParts(row).list.append(el('li'));
wireUserRow(doc, row);
rowParts(row).name.focus();
return rowParts(row).results.style.display;
JS);

        $this->assertSame('none', $result);
    }

    public function test_escape_on_a_result_closes_them_and_returns_to_the_search(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const parts = rowParts(row);
await wireUserRow(doc, row, { fetch: fetchPeople(people(2)) }).search('Fi');
const escape = key('Escape');
parts.list.children[0].fire('keydown', escape);
return { shown: parts.results.style.display, focused: doc.activeElement === parts.name, id: parts.id.value };
JS);

        $this->assertSame(['shown' => 'none', 'focused' => true, 'id' => ''], $result);
    }

    public function test_escape_in_the_search_closes_results_and_keeps_the_text(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const parts = rowParts(row);
await wireUserRow(doc, row, { fetch: fetchPeople(people(2)) }).search('Fi');
parts.name.value = 'Fi';
const escape = key('Escape');
parts.name.fire('keydown', escape);
parts.name.focus();
return { shown: parts.results.style.display, text: parts.name.value, prevented: escape.prevented };
JS);

        $this->assertSame(['shown' => 'none', 'text' => 'Fi', 'prevented' => true], $result);
    }

    public function test_row_parts_are_found_by_the_row_ids(): void
    {
        $result = $this->run_(<<<'JS'
const parts = rowParts(buildUserRow(doc, 3, '7'));
return [parts.name.id, parts.id.id, parts.results.id, parts.list.id, parts.remove.textContent];
JS);

        $this->assertSame(['user3name', 'user3id', 'userResultsDiv3', 'userResults3', '-'], $result);
    }

    public function test_edit_use_escapes_person_names(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-edit.php');

        $this->assertStringContainsString("h(\$user['name'])", $source);
    }

    public function test_results_close_when_focus_leaves_the_row(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const parts = rowParts(row);
await wireUserRow(doc, row, { fetch: fetchPeople(people(2)) }).search('Fi');
row.fire('focusout', { relatedTarget: parts.list.children[0] });
const withinRow = parts.results.style.display;
row.fire('focusout', { relatedTarget: el('input') });
const hidden = parts.results.style.display;
const labels = parts.list.children.map((li) => li.textContent);
parts.name.focus();
return [withinRow, hidden, parts.results.style.display, labels];
JS);

        $this->assertSame(['block', 'none', 'block', ['First0 Last0', 'First1 Last1']], $result);
    }

    public function test_a_row_search_uses_the_origin_meta_tag(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const calls = [];
await wireUserRow(doc, row, {
  fetch: async (url, opts) => {
    calls.push({ url, body: JSON.parse(opts.body) });
    return { json: async () => ({ users: [] }) };
  },
}).search('Fi');
return calls[0];
JS);

        $this->assertSame([
            'url' => 'https://api.staging.keeplore.app/users.php',
            'body' => ['query' => 'Fi', 'userid' => '7'],
        ], $result);
    }

    public function test_person_pick_is_read_when_a_search_runs(): void
    {
        $result = $this->run_(<<<'JS'
const row = buildUserRow(doc, 1, '7');
const parts = rowParts(row);
await wireUserRow(doc, row, { fetch: fetchPeople(people(1)) }).search('Fi');
return parts.list.children[0].textContent;
JS, false);

        $this->assertSame('First0 Last0', $result);
    }

    public function test_add_user_row_appends_a_wired_row_for_a_new_person(): void
    {
        $result = $this->run_(<<<'JS'
const section = el('section');
section.setAttribute('id', 'users');
const first = buildUserRow(doc, 0, '7');
section.append(first);
body.append(section);
addUserRow(doc, { id: 12, name: 'New Person' });
const added = section.children[1];
const parts = rowParts(added);
return [added.id, parts.id.value, parts.name.value, parts.name.dataset.userid, doc.activeElement === parts.name];
JS);

        $this->assertSame(['personRow1', '12', 'New Person', '7', true], $result);
    }

    public function test_record_pages_use_the_one_row_module(): void
    {
        foreach (['record-new.php', 'record-edit.php'] as $page) {
            $source = (string) file_get_contents(PROJECT_PATH . '/ui/uses/' . $page);
            $this->assertStringNotContainsString('searchUsersList.js', $source, $page);
            $this->assertStringContainsString('modules/getUsers.js', $source, $page);
        }
        $this->assertFileDoesNotExist(PROJECT_PATH . '/ui/uses/modules/searchUsersList.js');
    }

    /** @return mixed */
    private function run_(string $body, bool $preloadPersonPick = true)
    {
        $url = json_encode('file://' . PROJECT_PATH . '/ui/uses/modules/userRows.js');
        $personPick = json_encode('file://' . PROJECT_PATH . '/ui/shared/js/person-pick.js');
        $preload = $preloadPersonPick ? 'true' : 'false';
        $script = <<<JS
function el(tag) {
  const listeners = {};
  const node = {
    tagName: tag.toUpperCase(), children: [], attrs: {}, dataset: {}, style: {},
    className: '', id: '', value: '', textContent: '', tabIndex: -1, type: '',
    classList: { add(c) { node.className = (node.className + ' ' + c).trim(); } },
    setAttribute(k, v) {
      node.attrs[k] = String(v);
      if (k === 'id') node.id = String(v);
      if (k === 'type') node.type = String(v);
      if (k.startsWith('data-')) node.dataset[k.slice(5).replace(/-(\\w)/g, (m, c) => c.toUpperCase())] = String(v);
    },
    getAttribute(k) { return node.attrs[k] ?? null; },
    append(...kids) { node.children.push(...kids); },
    appendChild(kid) { node.children.push(kid); },
    replaceChildren() { node.children = []; },
    addEventListener(t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
    fire(t, e) { (listeners[t] || []).forEach((fn) => fn(e)); },
    focus() { doc.activeElement = node; node.fire('focus', {}); },
    remove() {},
    contains(other) { return other === node || node.all().includes(other); },
    all() { return node.children.flatMap((c) => [c, ...c.all()]); },
  };
  return node;
}
const body = el('body');
const doc = {
  activeElement: null,
  createElement: el,
  querySelector: (sel) => body.all().find((n) => n.id === sel.replace(/^\w*#/, '')) || null,
  querySelectorAll: (sel) => (sel === 'input.user'
    ? body.all().filter((n) => n.tagName === 'INPUT' && /\buser\b/.test(n.className))
    : []),
};
globalThis.document = {
  querySelector: (sel) => sel === 'meta[name="keeplore-api-origin"]'
    ? { content: 'api.staging.keeplore.app' }
    : null,
};
function rowsDoc(ids) {
  return { querySelectorAll: (sel) => (sel === 'input.user' ? ids.map((id) => ({ id })) : []) };
}
function people(n) {
  return Array.from({ length: n }, (_, i) => ({ id: i, FirstName: 'First' + i, LastName: 'Last' + i }));
}
function fetchPeople(users) {
  return async () => ({ ok: true, json: async () => ({ users }) });
}
function key(k) {
  const e = {
    key: k, prevented: false, stopped: false,
    preventDefault() { e.prevented = true; },
    stopPropagation() { e.stopped = true; },
  };
  return e;
}
const preload = {$preload};
if (preload) await import({$personPick});
const personPickBeforeRows = typeof globalThis.PersonPick;
const userRows = await import({$url});
if (!preload) {
  if (personPickBeforeRows !== 'undefined' || typeof globalThis.PersonPick !== 'undefined') {
    throw new Error('person pick loaded with the row module');
  }
  await import({$personPick});
}
const { nextUserIndex, buildUserRow, wireUserRow, addUserRow, rowParts } = userRows;
const out = await (async () => { {$body} })();
process.stdout.write(JSON.stringify(out));
JS;

        $cmd = 'node --input-type=module -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);
        return json_decode($raw, true);
    }
}
