import { API_ORIGIN } from "./publicEnvironmentVariables.js";

// Person rows on Record Use and Edit Use. The server renders the rows a
// form opens with; + adds more with addUserRow. Every row, either way, is
// wired here: live search, picking a result by click or Tab then Enter,
// and its remove button. Functions take the document so tests can pass a
// fake one.

const MAX_RESULTS = 10;

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

function displayName(user) {
  return user.FirstName + " " + user.LastName;
}

// Fill list with up to 10 people. Each is a tab stop; click, Enter, or
// Space picks it, and Escape calls onEscape.
export function renderUserResults(doc, list, users, onPick, onEscape = () => {}) {
  list.replaceChildren();
  users.slice(0, MAX_RESULTS).forEach((user) => {
    const li = doc.createElement("li");
    li.tabIndex = 0;
    li.textContent = displayName(user);
    li.addEventListener("click", () => onPick(user));
    li.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onEscape();
        return;
      }
      if (event.key !== "Enter" && event.key !== " ") return;
      // Also stops Record Use's Enter-submits-the-form keypress.
      event.preventDefault();
      onPick(user);
    });
    list.append(li);
  });
}

// A row added with +, matching the person-row markup Record Use and Edit
// Use render.
export function buildUserRow(doc, index, userid) {
  const row = doc.createElement("div");
  row.setAttribute("id", "SwSDiv" + index);
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

function rowParts(row) {
  const all = descendants(row);
  return {
    name: all.find((n) => n.tagName === "INPUT" && n.type === "search"),
    id: all.find((n) => n.tagName === "INPUT" && n.type === "hidden"),
    remove: all.find((n) => n.tagName === "BUTTON" && /\bremove-user\b/.test(n.className)),
    results: all.find((n) => n.tagName === "DIV"),
    list: all.find((n) => n.tagName === "UL"),
  };
}

// The signed-in user's people matching query, or [] after sending a
// signed-out visitor to log in.
export async function searchPeople(query, userid) {
  const response = await fetch("https://" + API_ORIGIN + "/users.php", {
    method: "POST",
    credentials: "include",
    body: JSON.stringify({ query, userid }),
  });
  const data = await response.json();
  if (data.authenticated == false) {
    location.href = "/login.php";
    return [];
  }
  return data.users || [];
}

// Wire one row. Returns { search } so callers (and tests) can run a
// search and wait for its results.
export function wireUserRow(doc, row, { search = searchPeople } = {}) {
  const parts = rowParts(row);
  let latestSearch = 0;

  function hideResults() {
    parts.results.style.display = "none";
  }

  // Clear, not just hide, so refocusing the search doesn't reopen them.
  function closeResults() {
    parts.list.replaceChildren();
    hideResults();
    parts.name.focus();
  }

  function pick(user) {
    parts.id.value = String(user.id);
    parts.name.value = displayName(user);
    closeResults();
  }

  async function runSearch(query) {
    const thisSearch = ++latestSearch;
    if (query.length === 0) {
      parts.list.replaceChildren();
      hideResults();
      return;
    }
    const users = await search(query, parts.name.dataset.userid);
    // A slower earlier search must not replace newer results.
    if (thisSearch !== latestSearch) return;
    renderUserResults(doc, parts.list, users, pick, closeResults);
    parts.results.style.display = users.length > 0 ? "block" : "none";
  }

  // Server markup may carry a placeholder result; start empty.
  parts.list.replaceChildren();

  parts.name.addEventListener("input", () => runSearch(parts.name.value));
  parts.name.addEventListener("focus", () => {
    if (parts.list.children.length > 0) parts.results.style.display = "block";
  });
  row.addEventListener("focusout", (event) => {
    if (!row.contains(event.relatedTarget)) hideResults();
  });
  if (parts.remove) {
    parts.remove.addEventListener("click", (event) => {
      event.preventDefault();
      row.remove();
    });
  }

  return { search: runSearch };
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
