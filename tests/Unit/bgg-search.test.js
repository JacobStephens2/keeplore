const { test } = require('node:test');
const assert = require('node:assert/strict');
const bggSearch = require('../../ui/bgg-search/bgg-search.js');

const JUST_ONE = { id: 0, name: 'Just One', best: 6, votes: 505, age: 8, rank: 159, average: 7.59, kept: false };
const UNO = { id: 1, name: 'UNO', best: 4, votes: 335, age: 5, rank: 9000, average: 5.48, kept: true };
const NEW_GAME = { id: 2, name: 'another game', best: 7, votes: 12, age: null, rank: null, average: null, kept: false };

function sorted(key, dir) {
  return bggSearch.sortGames([JUST_ONE, UNO, NEW_GAME], [{ key, dir }]).map((game) => game.name);
}

test('numbers sort by value both ways', () => {
  assert.deepEqual(sorted('votes', 'desc'), ['Just One', 'UNO', 'another game']);
  assert.deepEqual(sorted('votes', 'asc'), ['another game', 'UNO', 'Just One']);
  assert.deepEqual(sorted('best', 'asc'), ['UNO', 'Just One', 'another game']);
});

test('a missing value sorts last whichever way', () => {
  assert.deepEqual(sorted('rank', 'asc'), ['Just One', 'UNO', 'another game']);
  assert.deepEqual(sorted('rank', 'desc'), ['UNO', 'Just One', 'another game']);
  assert.deepEqual(sorted('age', 'desc'), ['Just One', 'UNO', 'another game']);
  assert.deepEqual(sorted('average', 'asc'), ['UNO', 'Just One', 'another game']);
});

test('an empty Best sorts last, and zero does not', () => {
  const blank = { id: 3, name: 'blank', best: null, votes: 1, age: 4, rank: 1, average: 5, kept: false };
  const zero = { id: 4, name: 'zero', best: 0, votes: 1, age: 4, rank: 2, average: 5, kept: false };
  const names = (dir) => bggSearch.sortGames([JUST_ONE, blank, zero], [{ key: 'best', dir }]).map((game) => game.name);
  assert.deepEqual(names('asc'), ['zero', 'Just One', 'blank']);
  assert.deepEqual(names('desc'), ['Just One', 'zero', 'blank']);
});

test('names sort ignoring case', () => {
  assert.deepEqual(sorted('name', 'asc'), ['another game', 'Just One', 'UNO']);
});

test('kept games lead a descending Kept sort', () => {
  assert.deepEqual(sorted('kept', 'desc')[0], 'UNO');
  assert.deepEqual(sorted('kept', 'asc').slice(-1), ['UNO']);
});

function page(records, rowCount) {
  const rows = Array.from({ length: rowCount }, (_, index) => ({
    index,
    getAttribute() {
      throw new Error('sort must not read the row markup');
    },
  }));
  const tbody = { querySelectorAll: () => rows };
  const table = { querySelector: (selector) => (selector === 'tbody' ? tbody : null) };
  return {
    getElementById(id) {
      if (id === 'bgg-search-results') {
        return table;
      }
      if (id === 'bgg-search-rows') {
        return { textContent: JSON.stringify(records) };
      }
      return null;
    },
  };
}

test('the sort record is the embedded row, in the same order', () => {
  const records = [
    { id: 0, name: 'Just One', best: 6, kept: false },
    { id: 1, name: 'UNO', best: null, kept: true, 'bgg_rating:Gyges': null },
  ];
  const paired = bggSearch.recordsForTable(page(records, 2));
  assert.equal(paired[0].name, 'Just One');
  assert.equal(paired[0].id, 0);
  assert.equal(paired[0].row.index, 0);
  assert.equal(paired[1].best, null);
  assert.equal(paired[1]['bgg_rating:Gyges'], null);
  assert.equal(paired[1].row.index, 1);
  assert.equal(paired[1].id, 1);
});

test('a mismatched row count is not sorted', () => {
  assert.equal(bggSearch.recordsForTable(page([{ id: 0, name: 'Just One' }], 2)), null);
});

test('reviewer scores sort by value, a comment with no score last', () => {
  const games = [
    { ...JUST_ONE, 'bgg_rating:Gyges': 7 },
    { ...UNO, 'bgg_rating:Gyges': 8.5 },
    { ...NEW_GAME, 'bgg_rating:Gyges': null },
  ];
  const names = (dir) => bggSearch.sortGames(games, [{ key: 'bgg_rating:Gyges', dir }]).map((game) => game.name);
  assert.deepEqual(names('desc'), ['UNO', 'Just One', 'another game']);
  assert.deepEqual(names('asc'), ['Just One', 'UNO', 'another game']);
});
