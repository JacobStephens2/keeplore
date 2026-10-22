import { API_BASE } from "./publicEnvironmentVariables.js";

// Person rows on Record Use and Edit Use. The server renders the rows a
// form opens with; + adds more with addUserRow. Person search, the result
// list, and inline create live in person-pick.js, read from the global
// when a search runs. This adapter fills the picked person onto the row.
// Functions take the document so tests can pass a fake one.

const PEOPLE_SEARCH_URL = API_BASE + "/users.php";

function personPick() {
  const pick = typeof globalThis !== "undefined" ? globalThis.PersonPick : null;
  if (!pick) {
    throw new Error("Person pick is not loaded");
  }
  return pick;
}

// One past the highest user<i>name on the page, so a removed row's index
// is never handed out twice.
export function nextUserIndex(doc) {
  let highest = -1;
  doc.querySelectorAll("input.user").forEach((input) => {
    const match = /^user(\d+)name$/.exec(input.id);
    if (match) highest = Math.max(highest, Number(match[1]));
  });
  return highest + 1;
}

// A row added with +, matching the person-row markup Record Use and Edit
// Use render.
export function buildUserRow(doc, index, userid) {
  const row = doc.createElement("div");
  row.setAttribute("id", "personRow" + index);
  row.classList.add("person-row");

  const name = doc.createElement("input");
  name.setAttribute("type", "search");
  name.setAttribute("id", "user" + index + "name");
  name.setAttribute("name", "user[" + index + "][name]");
  name.setAttribute("data-userid", userid);
  name.setAttribute("autocomplete", "off");
  name.classList.add("user");

  const id = doc.createElement("input");
  id.setAttribute("type", "hidden");
  id.setAttribute("id", "user" + index + "id");
  id.setAttribute("name", "user[" + index + "][id]");

  const remove = doc.createElement("button");
  remove.setAttribute("type", "button");
  remove.setAttribute("aria-label", "Remove this person");
  remove.classList.add("user");
  remove.classList.add("remove-user");
  remove.textContent = "-";

  const results = doc.createElement("div");
  results.setAttribute("id", "userResultsDiv" + index);
  results.classList.add("userResults");
  results.style.display = "none";
  const list = doc.createElement("ul");
  list.setAttribute("id", "userResults" + index);
  list.classList.add("userResults");
  results.append(list);

  // Results right after the search, so Tab reaches them before remove.
  row.append(name, results, id, remove);
  return row;
}

function descendants(node) {
  return Array.from(node.children || []).flatMap((child) => [child, ...descendants(child)]);
}

// The row contract Record Use, Edit Use, and buildUserRow share: row
// personRow<i> holds user<i>name, user<i>id, userResultsDiv<i> around
// userResults<i>, and optionally a .remove-user button.
export function rowParts(row) {
  const i = row.id.replace("personRow", "");
  const all = descendants(row);
  const byId = (id) => all.find((n) => n.id === id);
  return {
    name: byId("user" + i + "name"),
    id: byId("user" + i + "id"),
    remove: all.find((n) => /\bremove-user\b/.test(n.className)),
    results: byId("userResultsDiv" + i),
    list: byId("userResults" + i),
  };
}

// Wire one row. Returns { search } so callers (and tests) can run a
// search and wait for its results. fetch is the call the row already
// used; tests pass a fake one.
export function wireUserRow(doc, row, { fetch: fetchFn } = {}) {
  const parts = rowParts(row);

  function hideResults() {
    parts.results.style.display = "none";
  }

  // Clear, not just hide, so refocusing the search doesn't reopen them.
  // Escape in the field keeps what was typed (type=search would clear it).
  function closeResultsAndReturnToSearch() {
    parts.list.replaceChildren();
    hideResults();
    parts.name.focus();
  }

  function pick(person) {
    parts.id.value = String(person.id);
    parts.name.value = person.name;
    closeResultsAndReturnToSearch();
  }

  // Server markup may carry a placeholder result; start empty.
  parts.list.replaceChildren();

  const bound = personPick().bindSearch({
    document: doc,
    input: parts.name,
    list: parts.list,
    url: PEOPLE_SEARCH_URL,
    userid: parts.name.dataset.userid,
    fetch: fetchFn,
    onPick: pick,
    onEscape: closeResultsAndReturnToSearch,
    setOpen(open) {
      parts.results.style.display = open ? "block" : "none";
    },
  });

  parts.name.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || parts.list.children.length === 0) return;
    event.preventDefault();
    closeResultsAndReturnToSearch();
  });
  parts.name.addEventListener("focus", () => {
    if (parts.list.children.length > 0) parts.results.style.display = "block";
  });
  row.addEventListener("focusout", (event) => {
    if (!row.contains(event.relatedTarget)) bound.hide();
  });
  if (parts.remove) {
    parts.remove.addEventListener("click", (event) => {
      event.preventDefault();
      row.remove();
    });
  }

  return { search: bound.search };
}

// + : append an empty row (or one for a just-created person) and focus it.
export function addUserRow(doc, prefill) {
  const userid = doc.querySelector("#user0name").dataset.userid;
  const row = buildUserRow(doc, nextUserIndex(doc), userid);
  doc.querySelector("section#users").appendChild(row);
  wireUserRow(doc, row);

  const parts = rowParts(row);
  if (prefill) {
    parts.id.value = String(prefill.id);
    parts.name.value = prefill.name;
  }
  parts.name.focus();
}
