// Reusable filter utilities for the artifact management tool
//
// Provides helpers for:
//   1. Reading and writing filter state to/from URL query parameters,
//      so filters survive page reloads and can be shared via links.
//   2. Client-side filtering of artifact lists by type and kept status.
//   3. A toggle-visibility helper for the filter panel (replacing the
//      inline code in shared/filter_button.js).
//
// All functions are pure / side-effect-free unless noted, making them
// easy to test and compose.
//
// Usage:
//
//   import FilterUtils from '/shared/js/filter-utils.js';
//
//   // On page load, hydrate filters from URL
//   const filters = FilterUtils.readFiltersFromUrl();
//   // => { type: 'board', kept: 'yes', search: 'catan' }
//
//   // After user changes a filter, push new state to URL
//   FilterUtils.writeFiltersToUrl({ type: 'card', kept: 'all' });
//
//   // Filter a list of artifacts client-side
//   const visible = FilterUtils.applyFilters(artifacts, { type: 'board', kept: 'yes' });

const FilterUtils = {

  // ---------------------------------------------------------------------------
  // URL parameter management
  // ---------------------------------------------------------------------------

  /**
   * Read the current filter state from URL query parameters.
   *
   * Recognised keys (all optional):
   *   type   - artifact type name (e.g. "board", "card")
   *   kept   - "yes" | "no" | "all"
   *   search - free-text search term
   *   page   - current page number
   *
   * Unrecognised keys are preserved in the returned object so that
   * page-specific params are not lost.
   *
   * @returns {object} Plain object of current filter values
   */
  readFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const filters = {};

    for (const [key, value] of params.entries()) {
      filters[key] = value;
    }

    return filters;
  },

  /**
   * Write filter state into the URL query string, replacing the current
   * history entry (so the Back button isn't cluttered).
   *
   * Falsy values and the string "all" are stripped to keep the URL clean.
   *
   * @param {object} filters - Key/value pairs to serialise
   * @param {object} [options]
   * @param {boolean} [options.pushState=false] - Use pushState instead of replaceState
   */
  writeFiltersToUrl(filters, options = {}) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
      // Skip empty / default values to keep URLs short
      if (value != null && value !== '' && value !== 'all') {
        params.set(key, value);
      }
    }

    const qs = params.toString();
    const newUrl = qs
      ? `${window.location.pathname}?${qs}`
      : window.location.pathname;

    if (options.pushState) {
      window.history.pushState(null, '', newUrl);
    } else {
      window.history.replaceState(null, '', newUrl);
    }
  },

  // ---------------------------------------------------------------------------
  // Client-side artifact filtering
  // ---------------------------------------------------------------------------

  /**
   * Filter a list of artifact objects based on the given criteria.
   *
   * Each artifact is expected to have at least:
   *   - type (string)          - the artifact type name
   *   - is_kept (string/int)   - kept flag; kept means kept only, format
   *                              flags never affect membership
   *   - Title (string)         - used for free-text search
   *
   * @param {Array<object>} artifacts - The full list to filter
   * @param {object} criteria
   * @param {string} [criteria.type]   - Type to filter by (exact match, case-insensitive)
   * @param {string} [criteria.kept]   - "yes" | "no" | "all" / falsy
   * @param {string} [criteria.search] - Case-insensitive substring match on Title
   * @returns {Array<object>} A new array containing only the matching artifacts
   */
  applyFilters(artifacts, criteria = {}) {
    let result = artifacts;

    // -- Type filter ----------------------------------------------------------
    if (criteria.type && criteria.type !== 'all') {
      const targetType = criteria.type.toLowerCase();
      result = result.filter(
        (a) => a.type && a.type.toLowerCase() === targetType
      );
    }

    // -- Kept filter ----------------------------------------------------------
    // Kept means kept only: the single is_kept flag decides membership.
    if (criteria.kept && criteria.kept !== 'all') {
      if (criteria.kept === 'yes') {
        result = result.filter(
          (a) => _isKept(a.is_kept)
        );
      } else if (criteria.kept === 'no') {
        result = result.filter(
          (a) => !_isKept(a.is_kept)
        );
      }
    }

    // -- Free-text search on Title --------------------------------------------
    if (criteria.search && criteria.search.trim() !== '') {
      const term = criteria.search.trim().toLowerCase();
      result = result.filter(
        (a) => a.Title && a.Title.toLowerCase().includes(term)
      );
    }

    return result;
  },

  // ---------------------------------------------------------------------------
  // Filter panel visibility toggle
  // ---------------------------------------------------------------------------

  /**
   * Bind a toggle button to show/hide a filter form.
   *
   * This replaces the inline code in shared/filter_button.js with a
   * configurable, reusable version.
   *
   * @param {object} config
   * @param {string} config.buttonSelector - CSS selector for the toggle button
   * @param {string} config.panelSelector  - CSS selector for the filter form/panel
   * @param {string} [config.showLabel='Hide Filters'] - Button text when panel is visible
   * @param {string} [config.hideLabel='Show Filters'] - Button text when panel is hidden
   * @returns {object} Controller with show(), hide(), toggle(), destroy()
   */
  bindFilterToggle(config) {
    const {
      buttonSelector,
      panelSelector,
      showLabel = 'Hide Filters',
      hideLabel = 'Show Filters',
    } = config;

    const button = document.querySelector(buttonSelector);
    const panel  = document.querySelector(panelSelector);

    if (!button || !panel) {
      console.warn(
        '[FilterUtils.bindFilterToggle] Could not find elements:',
        { buttonSelector, panelSelector }
      );
      return { show() {}, hide() {}, toggle() {}, destroy() {} };
    }

    function show() {
      panel.style.display = 'block';
      button.textContent = showLabel;
    }

    function hide() {
      panel.style.display = 'none';
      button.textContent = hideLabel;
    }

    function toggle() {
      if (panel.style.display === 'none') {
        show();
      } else {
        hide();
      }
    }

    button.addEventListener('click', toggle);

    return {
      show,
      hide,
      toggle,
      destroy() {
        button.removeEventListener('click', toggle);
      },
    };
  },

  // ---------------------------------------------------------------------------
  // Utility: build type filter <select> from API data
  // ---------------------------------------------------------------------------

  /**
   * Populate a <select> element with artifact types fetched from the API.
   *
   * @param {string} selectSelector - CSS selector for the <select> element
   * @param {Array<{id: number, type: string}>} types - Array of type objects
   *        as returned by ApiClient.getTypes()
   * @param {string} [selectedValue] - Pre-select this value if present
   */
  populateTypeSelect(selectSelector, types, selectedValue = null) {
    const select = document.querySelector(selectSelector);
    if (!select) {
      console.warn('[FilterUtils.populateTypeSelect] Element not found:', selectSelector);
      return;
    }

    // Preserve or add an "All" option
    select.innerHTML = '';

    const allOption = document.createElement('option');
    allOption.value = 'all';
    allOption.textContent = 'All Types';
    select.appendChild(allOption);

    for (const t of types) {
      const option = document.createElement('option');
      option.value = t.type;
      option.textContent = t.type;
      if (selectedValue && t.type === selectedValue) {
        option.selected = true;
      }
      select.appendChild(option);
    }
  },
};

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Determine whether a "kept" flag counts as truthy.
 * The database stores these as various types (1, "1", "yes", etc.)
 */
function _isKept(value) {
  if (value == null) return false;
  if (typeof value === 'number') return value > 0;
  if (typeof value === 'string') {
    const v = value.trim().toLowerCase();
    return v !== '' && v !== '0' && v !== 'no' && v !== 'false';
  }
  return Boolean(value);
}

export default FilterUtils;
