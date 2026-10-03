import { addUserRow, wireUserRow } from "./userRows.js";

// Wire the person rows the server rendered, and + to add another.
document
  .querySelectorAll("section#users .person-row, section#users div.sweetSpot")
  .forEach((row) => wireUserRow(document, row));

document
  .querySelector("button#addUser")
  .addEventListener("click", function (event) {
    event.preventDefault();
    addUserRow();
  });
