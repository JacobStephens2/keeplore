import SearchComponent from '/shared/js/search-component.js';

const optionsEl = document.getElementById('chosen-item-options');
const chosenItemSearch = document.getElementById('chosen_item_search');
const chosenItemId = document.getElementById('chosen_item_id');
const chosenItemResults = document.getElementById('chosen_item_results');
const chosenItemResultsList = document.getElementById('chosen_item_results_list');

if (optionsEl && chosenItemSearch && chosenItemId && chosenItemResults && chosenItemResultsList) {
  const items = JSON.parse(optionsEl.textContent);

  function resultRows() {
    return [...chosenItemResultsList.querySelectorAll('li')];
  }

  function setChosenItem(item) {
    chosenItemId.value = String(item.id);
    chosenItemSearch.value = item.label;
  }

  function bindTypedTitle() {
    chosenItemId.value = '';
    const typed = chosenItemSearch.value.toLowerCase();
    const match = items.find((item) => item.label.toLowerCase() === typed);
    if (match) {
      chosenItemId.value = String(match.id);
    }
  }

  chosenItemSearch.addEventListener('input', bindTypedTitle);

  chosenItemSearch.addEventListener('keydown', (event) => {
    const rows = resultRows();
    if (event.key === 'Escape') {
      chosenItemResults.style.display = 'none';
      return;
    }
    if (!rows.length) {
      return;
    }
    const activeIndex = rows.findIndex((row) => row.classList.contains('is-active'));
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const delta = event.key === 'ArrowDown' ? 1 : -1;
      const next = activeIndex < 0
        ? (delta > 0 ? 0 : rows.length - 1)
        : (activeIndex + delta + rows.length) % rows.length;
      rows.forEach((row) => row.classList.remove('is-active'));
      rows[next].classList.add('is-active');
      rows[next].scrollIntoView({ block: 'nearest' });
    } else if (event.key === 'Enter') {
      const target = rows[activeIndex] || (rows.length === 1 ? rows[0] : null);
      if (target) {
        event.preventDefault();
        target.click();
      }
    }
  });

  SearchComponent.create({
    inputSelector: '#chosen_item_search',
    resultsSelector: '#chosen_item_results_list',
    wrapperSelector: '#chosen_item_results',
    fetchResults: async (query) => {
      const needle = query.toLowerCase();
      return items.filter((item) => item.label.toLowerCase().includes(needle));
    },
    onSelect: setChosenItem,
    maxResults: items.length || 1,
    debounceMs: 0,
  });
}
