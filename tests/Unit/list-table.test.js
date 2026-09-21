const { test } = require('node:test');
const assert = require('node:assert/strict');
const listTable = require('../../ui/shared/js/list-table.js');

test('blank search keeps every haystack', () => {
  assert.equal(listTable.fieldsMatchSearch(['Ada Lovelace'], ''), true);
  assert.equal(listTable.fieldsMatchSearch(['Ada Lovelace'], '   '), true);
});

test('multi-word search requires every part of the haystack', () => {
  assert.equal(listTable.fieldsMatchSearch(['Ada Lovelace', 'F'], 'ada love'), true);
  assert.equal(listTable.fieldsMatchSearch(['Ada Lovelace', 'F'], 'ada turing'), false);
});

test('compareValues flips for desc', () => {
  assert.equal(listTable.compareValues('a', 'b', 'asc') < 0, true);
  assert.equal(listTable.compareValues('a', 'b', 'desc') > 0, true);
});

test('sortRecords uses compare then id', () => {
  const rows = [
    { id: 2, name: 'b' },
    { id: 1, name: 'a' },
    { id: 3, name: 'a' },
  ];
  const byName = listTable.sortRecords(rows, [{ key: 'name', dir: 'asc' }], function (a, b, key, dir) {
    return listTable.compareValues(a[key], b[key], dir);
  });
  assert.deepEqual(byName.map((row) => row.id), [1, 3, 2]);
});
