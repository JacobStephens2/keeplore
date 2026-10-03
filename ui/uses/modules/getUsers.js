import { API_ORIGIN } from "./publicEnvironmentVariables.js";

// Index for the next interactor row, based on how many interactor search
// inputs are currently present (each row contributes exactly one input.user).
function nextUserIndex() {
  return document.querySelectorAll("input.user").length;
}

// Build an interactor row (search input + hidden id + remove button + results
// list) and append it to section#users. Pass an optional { id, name } to
// pre-select an interactor (used when a brand-new person was just created).
// Returns the row index that was used.
export function addUserRow(prefill) {
  const index = nextUserIndex();

  // Container
  const div = document.createElement("div");
  div.setAttribute("id", "SwSDiv" + index);
  div.classList.add("sweetSpot");

  // Search input
  const input = document.createElement("input");
  input.setAttribute("id", "user" + index + "name");
  input.setAttribute("name", "user[" + index + "][name]");
  input.setAttribute("data-userid", document.querySelector("#user0name").dataset.userid);
  input.setAttribute("type", "search");
  input.classList.add("user");

  // Hidden id that actually gets submitted
  const hiddenInput = document.createElement("input");
  hiddenInput.setAttribute("id", "user" + index + "id");
  hiddenInput.setAttribute("name", "user[" + index + "][id]");
  hiddenInput.setAttribute("type", "hidden");

  // Autocomplete results list
  const ul = document.createElement("ul");
  ul.setAttribute("id", "userList" + index);
  ul.classList.add("user");

  // Remove (-) button
  const button = document.createElement("button");
  button.setAttribute("id", "RemoveUser" + index);
  button.classList.add("user");
  button.type = "button";
  button.innerText = "-";
  button.addEventListener("click", function (event) {
    event.preventDefault();
    div.remove();
  });

  // Live search
  input.addEventListener("input", function (event) {
    const requestBody = {
      query: event.target.value,
      userid: event.srcElement.dataset.userid,
    };
    fetch("https://" + API_ORIGIN + "/users.php", {
      method: "POST",
      credentials: "include",
      body: JSON.stringify(requestBody),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.authenticated == false) {
          location.href = "/login.php";
          return;
        }
        ul.innerHTML = "";
        if (event.target.value.length === 0) {
          ul.style.display = "none";
          return;
        }
        ul.style.display = "block";
        const resultsLength = data.users.length > 10 ? 10 : data.users.length;
        for (let i = 0; i < resultsLength; i++) {
          const li = document.createElement("li");
          li.value = data.users[i].id;
          li.innerText = data.users[i].FirstName + " " + data.users[i].LastName;
          li.addEventListener("click", function () {
            hiddenInput.value = data.users[i].id;
            input.value = data.users[i].FirstName + " " + data.users[i].LastName;
            ul.style.display = "none";
          });
          ul.append(li);
        }
      });
  });

  div.append(input);
  div.append(hiddenInput);
  div.append(button);
  div.append(ul);

  document.querySelector("section#users").appendChild(div);

  if (prefill) {
    hiddenInput.value = prefill.id;
    input.value = prefill.name;
  }

  input.focus();
  return index;
}

document
  .querySelector("button#addUser")
  .addEventListener("click", function (event) {
    event.preventDefault();
    addUserRow();
  });

// Record another use with this group: the server lists everyone after the
// first person in data-prefill, and each gets its own row.
JSON.parse(document.querySelector("section#users")?.dataset.prefill || "[]")
  .forEach((person) => addUserRow(person));
