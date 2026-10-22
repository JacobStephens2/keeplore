const { test } = require('node:test');
const assert = require('node:assert/strict');
const itemsList = require('../../ui/shared/js/items-list.js');

function titles(items, key, dir) {
  return itemsList.sortItems(items, [{ key, dir }]).map((item) => item.title);
}

// Cell text names a different number than the sort value, so an order that
// came from parsing the label would not match.
test('players sort by the row value, and a missing count sorts last either way', () => {
  const items = [
    { id: 1, title: 'Range', players: '9–12 (best 10)', players_sort: 2 },
    { id: 2, title: 'Sweet', players: 'best 1', players_sort: 3 },
    { id: 3, title: 'None', players: '4', players_sort: null },
  ];
  assert.deepEqual(titles(items, 'players', 'asc'), ['Range', 'Sweet', 'None']);
  assert.deepEqual(titles(items, 'players', 'desc'), ['Sweet', 'Range', 'None']);
});

test('time sorts by the row value, and a missing play time sorts last either way', () => {
  const items = [
    { id: 1, title: 'Short', time: '120 min', time_sort: 30 },
    { id: 2, title: 'Long', time: '15 min', time_sort: 90 },
    { id: 3, title: 'None', time: '45 min', time_sort: null },
  ];
  assert.deepEqual(titles(items, 'time', 'asc'), ['Short', 'Long', 'None']);
  assert.deepEqual(titles(items, 'time', 'desc'), ['Long', 'Short', 'None']);
});

test('age sorts by the row value, and a missing age sorts last either way', () => {
  const items = [
    { id: 1, title: 'Young', age: '14+', age_sort: 8 },
    { id: 2, title: 'Older', age: '3+', age_sort: 12 },
    { id: 3, title: 'None', age: '1+', age_sort: null },
  ];
  assert.deepEqual(titles(items, 'age', 'asc'), ['Young', 'Older', 'None']);
  assert.deepEqual(titles(items, 'age', 'desc'), ['Older', 'Young', 'None']);
});

test('the BoardGameGeek average sorts by the rounded value, and a missing average sorts below every score', () => {
  const items = [
    { id: 1, title: 'High', bgg_average: '1', bgg_average_sort: 8.5 },
    { id: 2, title: 'Low', bgg_average: '10', bgg_average_sort: 6 },
    { id: 3, title: 'None', bgg_average: '7', bgg_average_sort: null },
  ];
  assert.deepEqual(titles(items, 'bgg_average', 'desc'), ['High', 'Low', 'None']);
  assert.deepEqual(titles(items, 'bgg_average', 'asc'), ['None', 'Low', 'High']);
});

test('a reviewer sorts by the stored sort value, a comment with no score as 0, and a missing rating below every score', () => {
  const items = [
    { id: 1, title: 'Ten', bgg_ratings: { Gyges: { rating: 1, score_text: '1', sort: 10 } } },
    { id: 2, title: 'One', bgg_ratings: { Gyges: { rating: 10, score_text: '10', sort: 1 } } },
    { id: 3, title: 'Comment', bgg_ratings: { Gyges: { rating: null, score_text: '9', sort: 0, comment: 'Note' } } },
    { id: 4, title: 'Zero', bgg_ratings: { Gyges: { rating: 8, score_text: '8', sort: 0 } } },
    { id: 5, title: 'Missing', bgg_ratings: {} },
  ];
  assert.deepEqual(titles(items, 'bgg_rating:Gyges', 'desc'), ['Ten', 'One', 'Comment', 'Zero', 'Missing']);
  assert.deepEqual(titles(items, 'bgg_rating:Gyges', 'asc'), ['Missing', 'Comment', 'Zero', 'One', 'Ten']);
});

test('kept, name, tags, dates, average play time and candidate sort as they do today', () => {
  const items = [
    {
      id: 1, title: 'b', type: 'Book', tags: ['zebra'], is_kept: false,
      acq: '2020-01-01', most_recent_use: '', use_by: '2024-02-01', avg_time: 10, candidate: false,
    },
    {
      id: 2, title: 'A', type: 'game', tags: ['alpha', 'beta'], is_kept: true,
      acq: '2024-06-01', most_recent_use: '2024-05-01', use_by: '', avg_time: 0, candidate: true,
    },
  ];
  assert.deepEqual(titles(items, 'title', 'asc'), ['A', 'b']);
  assert.deepEqual(titles(items, 'type', 'asc'), ['b', 'A']);
  assert.deepEqual(titles(items, 'is_kept', 'desc'), ['A', 'b']);
  assert.deepEqual(titles(items, 'tags', 'asc'), ['A', 'b']);
  assert.deepEqual(titles(items, 'acq', 'desc'), ['A', 'b']);
  assert.deepEqual(titles(items, 'most_recent_use', 'asc'), ['b', 'A']);
  assert.deepEqual(titles(items, 'use_by', 'desc'), ['b', 'A']);
  assert.deepEqual(titles(items, 'avg_time', 'desc'), ['b', 'A']);
  assert.deepEqual(titles(items, 'candidate', 'desc'), ['A', 'b']);
});
