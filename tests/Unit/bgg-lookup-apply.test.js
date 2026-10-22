const { test } = require('node:test');
const assert = require('node:assert/strict');
const form = require('../../ui/artifacts/new-bgg.js');

// A document with the Item form's keep, replace, preview, and clear marks.
// The preview id is not the one the page renders, so a script that names
// the preview itself cannot satisfy the picture test.
function element(id, attrs, value) {
  const node = {
    id,
    value: value || '',
    hidden: false,
    alt: '',
    attrs: Object.assign({}, attrs),
    listeners: {},
    hasAttribute(name) {
      return Object.prototype.hasOwnProperty.call(this.attrs, name);
    },
    getAttribute(name) {
      return this.hasAttribute(name) ? this.attrs[name] : null;
    },
    setAttribute(name, next) {
      this.attrs[name] = String(next);
    },
    removeAttribute(name) {
      delete this.attrs[name];
    },
    addEventListener(type, fn) {
      (this.listeners[type] || (this.listeners[type] = [])).push(fn);
    },
    dispatchInput() {
      (this.listeners.input || []).forEach((fn) => fn({ target: this }));
    },
  };
  Object.defineProperty(node, 'src', {
    get() { return this.attrs.src || ''; },
    set(next) { this.attrs.src = String(next); },
  });
  Object.defineProperty(node, 'textContent', {
    get() { return this._text || ''; },
    set(next) { this._text = String(next); },
  });
  return node;
}

function itemDoc(options) {
  const opts = options || {};
  const values = opts.values || {};
  const marks = {
    Title: opts.edit ? { 'data-bgg-keep': '' } : {},
    SS: { 'data-bgg-clears': 'bgg_player_votes' },
    age: { 'data-bgg-clears': 'bgg_age_basis' },
    MnP: { 'data-bgg-clears': 'bgg_player_votes' },
    MxP: { 'data-bgg-clears': 'bgg_player_votes' },
    MnT: {},
    MxT: {},
    Yr: {},
    bgg_url: { 'data-bgg-clears': 'bgg_player_votes bgg_age_basis BGG_Rat' },
    bgg_player_votes: {},
    bgg_age_basis: {},
    BGG_Rat: { 'data-bgg-replace': '' },
  };
  if (opts.picture !== false) {
    marks.image_url = { 'data-bgg-replace': '', 'data-bgg-preview': 'coverSlot' };
  }
  const nodes = Object.keys(marks).map((id) => element(id, marks[id], values[id]));
  if (opts.picture !== false) {
    nodes.push(element('coverSlot', {}, ''));
  }
  if (opts.hints !== false) {
    nodes.push(element('MnP-bgg-basis', { 'data-bgg-basis': 'players', 'data-bgg-from': 'bgg_player_votes' }, ''));
    nodes.push(element('SS-bgg-basis', { 'data-bgg-basis': 'sweet_spot', 'data-bgg-from': 'bgg_player_votes' }, ''));
    nodes.push(element('age-bgg-basis', { 'data-bgg-basis': 'age', 'data-bgg-from': 'bgg_age_basis' }, ''));
  }
  return {
    getElementById(id) {
      return nodes.find((node) => node.id === id) || null;
    },
    querySelectorAll(selector) {
      const match = selector.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
      if (!match) {
        throw new Error('Unsupported selector: ' + selector);
      }
      return nodes.filter((node) => {
        if (!node.hasAttribute(match[1])) return false;
        return match[2] === undefined || node.getAttribute(match[1]) === match[2];
      });
    },
  };
}

const TYPED = {
  Title: 'Handmade',
  SS: '2, 3',
  age: '8',
  MnP: '2',
  MxP: '5',
  MnT: '15',
  MxT: '40',
  Yr: '1999',
  image_url: 'https://example.com/old.jpg',
  bgg_url: 'https://boardgamegeek.com/boardgame/1/old',
  bgg_player_votes: '4',
  bgg_age_basis: 'publisher',
  BGG_Rat: '6',
};

const MATCH = {
  Title: 'Catan',
  SS: '04',
  age: '10',
  MnP: '3',
  MxP: '4',
  MnT: '60',
  MxT: '120',
  Yr: '1995',
  image_url: 'https://cf.geekdo-images.com/catan.jpg',
  bgg_url: 'https://boardgamegeek.com/boardgame/13/catan',
  bgg_player_votes: '19',
  bgg_age_basis: 'community',
  BGG_Rat: '7.09',
};

test('a match fills the item form through its posted input names', () => {
  const doc = itemDoc();
  form.apply(doc, MATCH);
  assert.equal(doc.getElementById('Title').value, 'Catan');
  assert.equal(doc.getElementById('age').value, '10');
  assert.equal(doc.getElementById('SS').value, '04');
  assert.equal(doc.getElementById('MnP').value, '3');
  assert.equal(doc.getElementById('MxP').value, '4');
  assert.equal(doc.getElementById('MnT').value, '60');
  assert.equal(doc.getElementById('MxT').value, '120');
  assert.equal(doc.getElementById('Yr').value, '1995');
  assert.equal(doc.getElementById('image_url').value, 'https://cf.geekdo-images.com/catan.jpg');
  assert.equal(doc.getElementById('bgg_url').value, 'https://boardgamegeek.com/boardgame/13/catan');
  assert.equal(doc.getElementById('bgg_player_votes').value, '19');
  assert.equal(doc.getElementById('bgg_age_basis').value, 'community');
  assert.equal(doc.getElementById('BGG_Rat').value, '7.09');
});

test('the stored column name does not fill minimum age', () => {
  const doc = itemDoc({ values: { age: '8' } });
  form.apply(doc, { Age: '10' });
  assert.equal(doc.getElementById('age').value, '8');
});

test('a match with no sweet spot, player count, or play time leaves what was typed', () => {
  const doc = itemDoc({ values: TYPED });
  form.apply(doc, { MnP: '', MxP: '', MnT: '', MxT: '', age: '10' });
  assert.equal(doc.getElementById('SS').value, '2, 3');
  assert.equal(doc.getElementById('MnP').value, '2');
  assert.equal(doc.getElementById('MxP').value, '5');
  assert.equal(doc.getElementById('MnT').value, '15');
  assert.equal(doc.getElementById('MxT').value, '40');
  assert.equal(doc.getElementById('age').value, '10');
});

test('a match replaces the picture and the bgg average and leaves a typed link', () => {
  const doc = itemDoc({ values: TYPED });
  const preview = doc.getElementById('coverSlot');
  preview.hidden = false;
  preview.src = 'https://example.com/old.jpg';
  form.apply(doc, { image_url: '', BGG_Rat: '' });
  assert.equal(doc.getElementById('image_url').value, '');
  assert.equal(doc.getElementById('BGG_Rat').value, '');
  assert.equal(doc.getElementById('bgg_url').value, TYPED.bgg_url);
  assert.equal(preview.hidden, true);
  assert.equal(preview.getAttribute('src'), null);
  assert.equal(preview.alt, '');
});

test('edit item keeps the name and ignores a picture', () => {
  const doc = itemDoc({ edit: true, picture: false, values: { Title: 'Quelf', age: '12' } });
  form.apply(doc, { Title: 'Catan', age: '10', image_url: 'https://cf.geekdo-images.com/catan.jpg' });
  assert.equal(doc.getElementById('Title').value, 'Quelf');
  assert.equal(doc.getElementById('age').value, '10');
  assert.equal(doc.getElementById('image_url'), null);
  assert.equal(
    form.filledMessage(doc, 'Catan'),
    'Filled from Catan and linked it. Save to keep the changes.'
  );
});

test('create item says the form was filled from the match', () => {
  assert.equal(form.filledMessage(itemDoc(), 'Catan'), 'Filled from Catan.');
});

test('the picture preview follows the picture input and the name in the form', () => {
  const doc = itemDoc({ values: { Title: 'Quelf' } });
  form.apply(doc, { Title: 'Catan', image_url: 'https://cf.geekdo-images.com/catan.jpg' });
  const preview = doc.getElementById('coverSlot');
  assert.equal(doc.getElementById('image_url').value, 'https://cf.geekdo-images.com/catan.jpg');
  assert.equal(preview.src, 'https://cf.geekdo-images.com/catan.jpg');
  assert.equal(preview.alt, 'Catan cover');
  assert.equal(preview.hidden, false);
});

test('a hand edit of player counts or sweet spot clears the player-vote basis and hides those hints', () => {
  ['MnP', 'MxP', 'SS'].forEach((id) => {
    const doc = itemDoc({
      values: { bgg_player_votes: '19', bgg_age_basis: 'community', BGG_Rat: '7.09' },
    });
    form.bindHandEdits(doc);
    form.showBases(doc, {
      players: 'From 19 BGG community votes',
      sweet_spot: 'From 19 BGG community votes',
      age: 'From the BGG community age poll',
    });
    doc.getElementById(id).dispatchInput();
    assert.equal(doc.getElementById('bgg_player_votes').value, '', id);
    assert.equal(doc.getElementById('bgg_age_basis').value, 'community', id);
    assert.equal(doc.getElementById('BGG_Rat').value, '7.09', id);
    assert.equal(doc.getElementById('MnP-bgg-basis').hidden, true, id);
    assert.equal(doc.getElementById('SS-bgg-basis').hidden, true, id);
    assert.equal(doc.getElementById('age-bgg-basis').hidden, false, id);
  });
});

test('a hand edit of minimum age clears the age basis and hides the age hint', () => {
  const doc = itemDoc({ values: { bgg_player_votes: '19', bgg_age_basis: 'community' } });
  form.bindHandEdits(doc);
  form.showBases(doc, { players: 'From 19 BGG community votes', age: 'From the BGG community age poll' });
  doc.getElementById('age').dispatchInput();
  assert.equal(doc.getElementById('bgg_age_basis').value, '');
  assert.equal(doc.getElementById('bgg_player_votes').value, '19');
  assert.equal(doc.getElementById('age-bgg-basis').hidden, true);
  assert.equal(doc.getElementById('MnP-bgg-basis').hidden, false);
});

test('a hand edit of the link clears both bases, those hints, and the bgg average', () => {
  const doc = itemDoc({
    values: {
      bgg_url: 'https://boardgamegeek.com/boardgame/13/catan',
      bgg_player_votes: '19',
      bgg_age_basis: 'community',
      BGG_Rat: '7.09',
    },
  });
  form.bindHandEdits(doc);
  form.showBases(doc, {
    players: 'From 19 BGG community votes',
    sweet_spot: 'From 19 BGG community votes',
    age: 'From the BGG community age poll',
  });
  doc.getElementById('bgg_url').dispatchInput();
  assert.equal(doc.getElementById('bgg_url').value, 'https://boardgamegeek.com/boardgame/13/catan');
  assert.equal(doc.getElementById('bgg_player_votes').value, '');
  assert.equal(doc.getElementById('bgg_age_basis').value, '');
  assert.equal(doc.getElementById('BGG_Rat').value, '');
  assert.equal(doc.getElementById('MnP-bgg-basis').hidden, true);
  assert.equal(doc.getElementById('SS-bgg-basis').hidden, true);
  assert.equal(doc.getElementById('age-bgg-basis').hidden, true);
});

test('create item clears the basis inputs on a hand edit even with no hints', () => {
  const doc = itemDoc({
    hints: false,
    values: { bgg_player_votes: '19', bgg_age_basis: 'community', BGG_Rat: '7.09' },
  });
  form.bindHandEdits(doc);
  doc.getElementById('SS').dispatchInput();
  assert.equal(doc.getElementById('bgg_player_votes').value, '');
  assert.equal(doc.getElementById('bgg_age_basis').value, 'community');
  assert.equal(doc.getElementById('MnP-bgg-basis'), null);
  doc.getElementById('bgg_url').dispatchInput();
  assert.equal(doc.getElementById('bgg_age_basis').value, '');
  assert.equal(doc.getElementById('BGG_Rat').value, '');
});

test('filling from a match does not clear the basis it just set', () => {
  const doc = itemDoc();
  form.bindHandEdits(doc);
  form.apply(doc, MATCH);
  assert.equal(doc.getElementById('bgg_player_votes').value, '19');
  assert.equal(doc.getElementById('bgg_age_basis').value, 'community');
  assert.equal(doc.getElementById('BGG_Rat').value, '7.09');
  doc.getElementById('age').dispatchInput();
  assert.equal(doc.getElementById('bgg_age_basis').value, '');
  assert.equal(doc.getElementById('bgg_player_votes').value, '19');
});

test('basis sentences from the lookup are written into the hints', () => {
  const doc = itemDoc();
  form.showBases(doc, {
    players: 'From 5 BGG community votes',
    sweet_spot: '',
    age: 'From the BGG community age poll (BGG does not publish its vote count)',
  });
  assert.equal(doc.getElementById('MnP-bgg-basis').textContent, 'From 5 BGG community votes');
  assert.equal(doc.getElementById('MnP-bgg-basis').hidden, false);
  assert.equal(doc.getElementById('SS-bgg-basis').textContent, '');
  assert.equal(doc.getElementById('SS-bgg-basis').hidden, true);
  assert.equal(
    doc.getElementById('age-bgg-basis').textContent,
    'From the BGG community age poll (BGG does not publish its vote count)'
  );
  assert.equal(doc.getElementById('age-bgg-basis').hidden, false);
});
