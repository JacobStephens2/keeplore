// Event page: filter the games and players to add, save packed marks as
// they are ticked, copy the plain-text list, and come back to the same
// place after removing a game inline.

bindAddFilter();
bindPackedMarks();
bindCopy();
keepScroll();

function bindAddFilter() {
  document.querySelectorAll('.event-add-form').forEach(bindAddForm);
}

// One add list, games or players: type to filter, and the submit button
// counts what is ticked. data-noun names what the list holds.
function bindAddForm(form) {
  const filter = form.querySelector('.event-add-filter');
  const gamesOnly = form.querySelector('.event-add-games-only');
  const list = form.querySelector('.event-add-list');
  const empty = form.querySelector('.event-add-empty');
  const submit = form.querySelector('.event-add-submit');
  if (!filter || !list) {
    return;
  }
  const noun = form.dataset.noun;
  const choices = [...list.querySelectorAll('.event-choice')];

  function updateSubmit() {
    const selected = list.querySelectorAll('input:checked').length;
    submit.disabled = selected === 0;
    submit.textContent = selected === 0 ? `Add selected ${noun}s`
      : `Add ${selected} ${selected === 1 ? noun : `${noun}s`}`;
  }

  function applyFilter() {
    const needle = filter.value.trim().toLowerCase();
    let shown = 0;
    choices.forEach((choice) => {
      // A ticked choice stays in view so it is not lost from sight.
      const match = choice.querySelector('input').checked
        || ((needle === '' || choice.dataset.title.includes(needle))
          && (!gamesOnly || !gamesOnly.checked || choice.dataset.game === '1'));
      choice.hidden = !match;
      shown += match ? 1 : 0;
    });
    empty.hidden = shown > 0;
  }

  filter.addEventListener('input', applyFilter);
  if (gamesOnly) {
    gamesOnly.addEventListener('change', applyFilter);
  }
  applyFilter();
  // Enter in the search box would otherwise submit whatever is ticked.
  filter.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
    }
  });
  list.addEventListener('change', updateSubmit);
  updateSubmit();
}

function bindPackedMarks() {
  const page = document.querySelector('.event-page');
  const tokenInput = document.querySelector('#event-pack-form input[name="csrf_token"]');
  const count = document.getElementById('event-packed-count');
  if (!page || !tokenInput) {
    return;
  }
  const boxes = [...document.querySelectorAll('.event-packed')];

  // A game shown in several groups has one box per group; they move together.
  function setPacked(itemId, packed) {
    boxes.filter((box) => box.dataset.itemId === itemId).forEach((box) => { box.checked = packed; });
    if (count) {
      count.textContent = String(new Set(boxes.filter((box) => box.checked).map((box) => box.dataset.itemId)).size);
    }
  }

  boxes.forEach((box) => {
    box.addEventListener('change', async () => {
      const itemId = box.dataset.itemId;
      const packed = box.checked;
      setPacked(itemId, packed);
      const body = new FormData();
      body.append('csrf_token', tokenInput.value);
      body.append('event_id', page.dataset.eventId);
      body.append('item_id', itemId);
      body.append('action', 'pack');
      body.append('is_packed', packed ? '1' : '0');
      try {
        const response = await fetch(page.dataset.itemUrl, { method: 'POST', body, credentials: 'same-origin' });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
      } catch (error) {
        setPacked(itemId, !packed);
        window.alert('That packed mark could not be saved. Try again.');
      }
    });
  });
}

function bindCopy() {
  const text = document.getElementById('event-text');
  const button = document.getElementById('event-text-copy');
  const status = document.getElementById('event-text-status');
  if (!text || !button) {
    return;
  }
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(text.value);
      status.textContent = 'Copied.';
    } catch (error) {
      text.focus();
      text.select();
      status.textContent = 'Press Ctrl+C (or Cmd+C) to copy.';
    }
  });
}

// A game removed from the grouped list reloads the page; return to where
// it was, so the next game to remove is still in view.
function keepScroll() {
  const page = document.querySelector('.event-page');
  if (!page) {
    return;
  }
  const key = 'keeplore-event-scroll';
  try {
    const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
    sessionStorage.removeItem(key);
    if (saved && saved.eventId === page.dataset.eventId) {
      window.scrollTo(0, saved.y);
    }
  } catch (error) {
    // Without storage the page simply opens at the top.
  }
  document.querySelectorAll('.event-keep-scroll').forEach((form) => {
    form.addEventListener('submit', () => {
      try {
        sessionStorage.setItem(key, JSON.stringify({ eventId: page.dataset.eventId, y: window.scrollY }));
      } catch (error) {
        // Nothing to keep without storage.
      }
    });
  });
}
