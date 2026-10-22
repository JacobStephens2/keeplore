const optionsEl = document.getElementById('chosen-item-options');
const chosenItemSearch = document.getElementById('chosen_item_search');
const chosenItemId = document.getElementById('chosen_item_id');
const chosenItemResults = document.getElementById('chosen_item_results');
const chosenItemResultsList = document.getElementById('chosen_item_results_list');

if (optionsEl && chosenItemSearch && chosenItemId && chosenItemResults && chosenItemResultsList) {
  const pick = globalThis.ItemPick;
  if (!pick) {
    throw new Error('Item pick is not loaded');
  }
  // Items already on the page. A typed name stays in its own field.
  pick.bind({
    document,
    input: chosenItemSearch,
    idInput: chosenItemId,
    list: chosenItemResultsList,
    items: JSON.parse(optionsEl.textContent),
    setOpen(open) {
      chosenItemResults.style.display = open ? 'block' : 'none';
    },
  });
}

bindParticipantFilter();

function bindParticipantFilter() {
  const participantSearch = document.getElementById('participant_search');
  const participantList = document.getElementById('proposal-participants');
  const emptyMessage = document.getElementById('participant-filter-empty');
  if (!participantSearch || !participantList) {
    return;
  }

  const rows = [...participantList.querySelectorAll('.proposal-choice')];

  function applyFilter() {
    const needle = participantSearch.value.trim().toLowerCase();
    let visible = 0;
    rows.forEach((row) => {
      const checked = row.querySelector('input')?.checked;
      const match = needle === '' || row.textContent.toLowerCase().includes(needle);
      const show = match || checked;
      row.hidden = !show;
      if (show) {
        visible += 1;
      }
    });
    if (emptyMessage) {
      emptyMessage.hidden = visible > 0 || needle === '';
    }
  }

  participantSearch.addEventListener('input', applyFilter);
  participantList.addEventListener('change', applyFilter);
}
