const { test } = require('node:test');
const assert = require('node:assert/strict');
const sort = require('../../ui/artifacts/items-table-sort.js');

const HEADERS_BEFORE_TAGS = [
  'Kept',
  'Type',
  'Name',
  'Tracking Start',
  'Recent Interaction',
  'Interact By',
];
const HEADERS_WITH_TAGS = [
  'Kept',
  'Type',
  'Tags',
  'Name (12)',
  'Tracking Start',
  'Recent Interaction',
  'Interact By',
];
const TRACKING_START_FALLBACK = [
  { column: 'Tracking Start', dir: 'desc' },
  { column: 'Recent Interaction', dir: 'desc' },
  { column: 'Interact By', dir: 'desc' },
];

function memoryStorage(initial) {
  const data = Object.assign({}, initial || {});
  return {
    getItem: (key) => (Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null),
    setItem: (key, value) => {
      data[key] = String(value);
    },
  };
}

test('fresh visit sorts Tracking Start most recent first, not Name', () => {
  const order = sort.restore(HEADERS_WITH_TAGS, memoryStorage(), TRACKING_START_FALLBACK);
  assert.deepEqual(order[0], [4, 'desc']);
});

test('Tracking Start sort from a column-header click survives reload after Tags is inserted', () => {
  const storage = memoryStorage();
  sort.persist(HEADERS_BEFORE_TAGS, storage, [[3, 'desc']]);
  const order = sort.restore(HEADERS_WITH_TAGS, storage, [{ column: 'Name', dir: 'desc' }]);
  assert.deepEqual(order[0], [4, 'desc']);
});

test('Name sort survives reload after Name moves next to Kept', () => {
  const storage = memoryStorage();
  sort.persist(HEADERS_WITH_TAGS, storage, [[3, 'desc']]);
  const headersNameAfterKept = [
    'Kept',
    'Name (12)',
    'Type',
    'Tags',
    'Tracking Start',
    'Recent Interaction',
    'Interact By',
  ];
  const order = sort.restore(headersNameAfterKept, storage, TRACKING_START_FALLBACK);
  assert.deepEqual(order[0], [1, 'desc']);
});
