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

test('a plain header click sorts by that column alone, flipping it on a second click', () => {
  const tagsThenGyges = [{ key: 'tags', dir: 'desc' }, { key: 'bgg_rating:Gyges', dir: 'desc' }];
  assert.deepEqual(listTable.nextSorts([], 'tags', false), [{ key: 'tags', dir: 'desc' }]);
  assert.deepEqual(listTable.nextSorts([{ key: 'tags', dir: 'desc' }], 'tags', false), [{ key: 'tags', dir: 'asc' }]);
  assert.deepEqual(listTable.nextSorts([{ key: 'tags', dir: 'asc' }], 'tags', false), [{ key: 'tags', dir: 'desc' }]);
  assert.deepEqual(listTable.nextSorts(tagsThenGyges, 'title', false), [{ key: 'title', dir: 'desc' }]);
});

test('a shift click adds the column as the next tie-breaker, or flips it if already sorted', () => {
  const tags = [{ key: 'tags', dir: 'desc' }];
  const both = listTable.nextSorts(tags, 'bgg_rating:Gyges', true);
  assert.deepEqual(both, [{ key: 'tags', dir: 'desc' }, { key: 'bgg_rating:Gyges', dir: 'desc' }]);
  assert.deepEqual(listTable.nextSorts(both, 'bgg_rating:Gyges', true), [
    { key: 'tags', dir: 'desc' },
    { key: 'bgg_rating:Gyges', dir: 'asc' },
  ]);
  assert.deepEqual(listTable.nextSorts([], 'tags', true), [{ key: 'tags', dir: 'desc' }]);
  assert.deepEqual(tags, [{ key: 'tags', dir: 'desc' }], 'the input list is not changed');
});

test('sorting by tags then a rating orders rating within each tag group', () => {
  const rows = [
    { id: 1, tag: 'beach', rating: 6 },
    { id: 2, tag: '', rating: 10 },
    { id: 3, tag: 'beach', rating: 9 },
    { id: 4, tag: 'family', rating: 7 },
  ];
  const sorted = listTable.sortRecords(rows, listTable.nextSorts([{ key: 'tag', dir: 'asc' }], 'rating', true), function (a, b, key, dir) {
    return listTable.compareValues(a[key], b[key], dir);
  });
  assert.deepEqual(sorted.map((row) => row.id), [2, 3, 1, 4]);
});

test('the sort summary names each level in order with its direction', () => {
  const labels = { tags: 'Tags', 'bgg_rating:Gyges': 'Gyges' };
  assert.equal(listTable.describeSorts([], labels), '');
  assert.equal(listTable.describeSorts([{ key: 'tags', dir: 'desc' }], labels), 'Sorted by Tags (descending)');
  assert.equal(
    listTable.describeSorts([{ key: 'tags', dir: 'asc' }, { key: 'bgg_rating:Gyges', dir: 'desc' }], labels),
    'Sorted by Tags (ascending), then Gyges (descending)'
  );
  assert.equal(listTable.describeSorts([{ key: 'mystery', dir: 'asc' }], labels), 'Sorted by mystery (ascending)');
});
