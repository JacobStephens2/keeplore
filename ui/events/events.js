// Event page: filter the games to add, save packed marks as they are
// ticked, and copy the plain-text list.

bindAddFilter();
bindPackedMarks();
bindCopy();

function bindAddFilter() {
  const filter = document.getElementById('event-add-filter');
  const gamesOnly = document.getElementById('event-add-games-only');
  const list = document.getElementById('event-add-list');
  const empty = document.getElementById('event-add-empty');
  const submit = document.getElementById('event-add-submit');
  if (!filter || !list) {
    return;
  }
  const choices = [...list.querySelectorAll('.event-choice')];

  function updateSubmit() {
    const selected = list.querySelectorAll('input:checked').length;
    submit.disabled = selected === 0;
    submit.textContent = selected === 0 ? 'Add selected games'
      : `Add ${selected} ${selected === 1 ? 'game' : 'games'}`;
  }

  function applyFilter() {
    const needle = filter.value.trim().toLowerCase();
    let shown = 0;
    choices.forEach((choice) => {
      // A ticked game stays in view so the choice is not lost from sight.
      const match = choice.querySelector('input').checked
        || ((needle === '' || choice.dataset.title.includes(needle))
          && (!gamesOnly.checked || choice.dataset.game === '1'));
      choice.hidden = !match;
      shown += match ? 1 : 0;
    });
    empty.hidden = shown > 0;
  }

  filter.addEventListener('input', applyFilter);
  gamesOnly.addEventListener('change', applyFilter);
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
