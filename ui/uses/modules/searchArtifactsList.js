import { API_BASE } from "./publicEnvironmentVariables.js";

// Record Use's item search. Item pick owns the list, the exact match,
// and the keyboard. This adapter asks the collection list, and it closes
// that list when the first person field is focused.

function loadItemPick() {
  const pick = typeof globalThis !== "undefined" ? globalThis.ItemPick : null;
  if (!pick) {
    throw new Error("Item pick is not loaded");
  }
  return pick;
}

const input = document.querySelector("input#SearchTitles");
const idInput = document.querySelector("input#SearchTitleSubmission");
const list = document.querySelector("ul.searchResults");
const results = document.querySelector("div.searchResults");

export const itemPick = loadItemPick().bind({
  document,
  input,
  idInput,
  list,
  url: API_BASE + "/artifacts.php",
  setOpen(open) {
    results.style.display = open ? "block" : "none";
  },
});

document.querySelector("#user0name").addEventListener("focus", function () {
  itemPick.hide();
});
