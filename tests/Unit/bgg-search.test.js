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

test('names sort ignoring case', () => {
  assert.deepEqual(sorted('name', 'asc'), ['another game', 'Just One', 'UNO']);
});

test('kept games lead a descending Kept sort', () => {
  assert.deepEqual(sorted('kept', 'desc')[0], 'UNO');
  assert.deepEqual(sorted('kept', 'asc').slice(-1), ['UNO']);
});

test('a row reads its numbers from data attributes, blanks as missing', () => {
  const attrs = { 'data-name': 'UNO', 'data-best': '4', 'data-votes': '335', 'data-age': '', 'data-rank': '9000', 'data-average': '5.48', 'data-kept': '1' };
  const row = { getAttribute: (name) => (name in attrs ? attrs[name] : null) };
  assert.deepEqual(
    bggSearch.gameFromRow(row, 3),
    { id: 3, name: 'UNO', best: 4, votes: 335, age: null, rank: 9000, average: 5.48, kept: true, row }
  );
});

test('a row reads each reviewer score as a sort key, a comment alone as missing', () => {
  const attrs = { 'data-name': 'UNO', 'data-kept': '0', 'data-ratings': '{"Gyges":6.5,"Tom":null}' };
  const row = { getAttribute: (name) => (name in attrs ? attrs[name] : null) };
  const game = bggSearch.gameFromRow(row, 0);
  assert.equal(game['bgg_rating:Gyges'], 6.5);
  assert.equal(game['bgg_rating:Tom'], null);
});

test('reviewer scores sort by value, unrated last', () => {
  const games = [
    { ...JUST_ONE, 'bgg_rating:Gyges': 7 },
    { ...UNO, 'bgg_rating:Gyges': 8.5 },
    { ...NEW_GAME },
  ];
  const names = (dir) => bggSearch.sortGames(games, [{ key: 'bgg_rating:Gyges', dir }]).map((game) => game.name);
  assert.deepEqual(names('desc'), ['UNO', 'Just One', 'another game']);
  assert.deepEqual(names('asc'), ['Just One', 'UNO', 'another game']);
});
