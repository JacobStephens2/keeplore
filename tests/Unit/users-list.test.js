const { test } = require('node:test');
const assert = require('node:assert/strict');
const usersList = require('../../ui/shared/js/users-list.js');

const ADA = { id: 12, name: 'Ada Lovelace', gender: 'F', age: 36 };
const ALAN = { id: 3, name: 'Alan Turing', gender: 'M', age: 41 };
const GRACE = { id: 20, name: 'Grace Hopper', gender: 'F', age: null };

test('blank search keeps every user', () => {
  assert.equal(usersList.userMatchesSearch(ADA, ''), true);
  assert.equal(usersList.userMatchesSearch(ADA, '   '), true);
});

test('search matches name, case-insensitive', () => {
  assert.equal(usersList.userMatchesSearch(ADA, 'ada'), true);
  assert.equal(usersList.userMatchesSearch(ADA, 'LOVELACE'), true);
  assert.equal(usersList.userMatchesSearch(ADA, 'turing'), false);
});

test('multi-word search requires every part', () => {
  assert.equal(usersList.userMatchesSearch(ADA, 'ada love'), true);
  assert.equal(usersList.userMatchesSearch(ADA, 'ada turing'), false);
});

test('search also matches gender and id', () => {
  assert.equal(usersList.userMatchesSearch(ALAN, 'm'), true);
  assert.equal(usersList.userMatchesSearch(ADA, '12'), true);
});

test('fresh list sorts most recent id first', () => {
  const sorted = usersList.sortUsers([ADA, ALAN, GRACE], [{ key: 'id', dir: 'desc' }]);
  assert.deepEqual(sorted.map((user) => user.id), [20, 12, 3]);
});

test('name sort is case-insensitive and missing age sorts last when age desc', () => {
  const byName = usersList.sortUsers([GRACE, ADA, ALAN], [{ key: 'name', dir: 'asc' }]);
  assert.deepEqual(byName.map((user) => user.name), ['Ada Lovelace', 'Alan Turing', 'Grace Hopper']);

  const byAge = usersList.sortUsers([GRACE, ADA, ALAN], [{ key: 'age', dir: 'desc' }]);
  assert.deepEqual(byAge.map((user) => user.name), ['Alan Turing', 'Ada Lovelace', 'Grace Hopper']);
});
