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

// Fill list with up to 10 people. Each is a tab stop; click, Enter, or
// Space picks it.
export function renderUserResults(doc, list, users, onPick) {
  list.replaceChildren();
  users.slice(0, MAX_RESULTS).forEach((user) => {
    const li = doc.createElement("li");
    li.tabIndex = 0;
    li.setAttribute("role", "option");
    li.textContent = user.FirstName + " " + user.LastName;
    li.addEventListener("click", () => onPick(user));
    li.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      // Also stops Record Use's Enter-submits-the-form keypress.
      event.preventDefault();
      onPick(user);
    });
    list.append(li);
  });
}

// A row added with +, matching the markup the server renders.
export function buildUserRow(doc, index, userid) {
  const row = doc.createElement("div");
  row.setAttribute("id", "SwSDiv" + index);
  row.classList.add("sweetSpot");

  const name = doc.createElement("input");
  name.setAttribute("type", "search");
  name.setAttribute("id", "user" + index + "name");
  name.setAttribute("name", "user[" + index + "][name]");
  name.setAttribute("data-userid", userid);
  name.setAttribute("data-listposition", index);
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

  function hideResults() {
    parts.results.style.display = "none";
  }

  function pick(user) {
    parts.id.value = String(user.id);
    parts.name.value = user.FirstName + " " + user.LastName;
    // Clear, not just hide, so refocusing the search doesn't reopen them.
    parts.list.replaceChildren();
    hideResults();
    parts.name.focus();
  }

  async function runSearch(query) {
    if (query.length === 0) {
      parts.list.replaceChildren();
      hideResults();
      return;
    }
    const users = await search(query, parts.name.dataset.userid);
    renderUserResults(doc, parts.list, users, pick);
    parts.results.style.display = users.length > 0 ? "block" : "none";
  }

  parts.name.addEventListener("input", () => runSearch(parts.name.value));
  parts.name.addEventListener("focus", () => {
    if (parts.list.children.length > 0) parts.results.style.display = "block";
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
export function addUserRow(prefill) {
  const userid = document.querySelector("#user0name").dataset.userid;
  const row = buildUserRow(document, nextUserIndex(document), userid);
  document.querySelector("section#users").appendChild(row);
  wireUserRow(document, row);

  const parts = rowParts(row);
  if (prefill) {
    parts.id.value = prefill.id;
    parts.name.value = prefill.name;
  }
  parts.name.focus();
}
