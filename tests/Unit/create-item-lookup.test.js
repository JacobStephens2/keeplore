const { test } = require('node:test');
const assert = require('node:assert/strict');
const lookup = require('../../ui/shared/js/create-item-lookup.js');

const CATAN = { id: 10, title: 'Catan', is_kept: true, type: 'table-game' };
const TICKET = { id: 11, title: 'Ticket to Ride', is_kept: false, type: 'table-game' };
const ZORK = { id: 2, title: 'Zork', is_kept: true, type: 'computer-game' };

test('blank search matches nothing so the create form is not buried', () => {
  assert.equal(lookup.itemMatchesSearch(CATAN, ''), false);
  assert.equal(lookup.itemMatchesSearch(CATAN, '   '), false);
});

test('search matches item name, case-insensitive', () => {
  assert.equal(lookup.itemMatchesSearch(CATAN, 'catan'), true);
  assert.equal(lookup.itemMatchesSearch(CATAN, 'CAT'), true);
  assert.equal(lookup.itemMatchesSearch(CATAN, 'zork'), false);
});

test('multi-word search requires every part of the name', () => {
  assert.equal(lookup.itemMatchesSearch(TICKET, 'ticket ride'), true);
  assert.equal(lookup.itemMatchesSearch(TICKET, 'ticket catan'), false);
});

test('search is by name, not type', () => {
  assert.equal(lookup.itemMatchesSearch(ZORK, 'computer-game'), false);
  assert.equal(lookup.itemMatchesSearch(ZORK, 'zork'), true);
});

test('fresh lookup sorts names A-Z', () => {
  const sorted = lookup.sortItems([ZORK, TICKET, CATAN], { key: 'title', dir: 'asc' });
  assert.deepEqual(sorted.map((item) => item.title), ['Catan', 'Ticket to Ride', 'Zork']);
});

test('kept sort puts kept items first when descending', () => {
  const sorted = lookup.sortItems([TICKET, CATAN, ZORK], { key: 'is_kept', dir: 'desc' });
  assert.deepEqual(sorted.map((item) => item.title), ['Zork', 'Catan', 'Ticket to Ride']);
});
