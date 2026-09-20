import { addUserRow } from "./getUsers.js";

// Lets you create a brand-new interactor (player) without leaving the page.
// On success the new person is added as a selected interactor row.

const showBtn = document.querySelector("#showNewInteractor");
const formWrap = document.querySelector("#newInteractorForm");
const firstInput = document.querySelector("#newInteractorFirst");
const lastInput = document.querySelector("#newInteractorLast");
const createBtn = document.querySelector("#createInteractor");
const cancelBtn = document.querySelector("#cancelNewInteractor");
const msg = document.querySelector("#newInteractorMsg");

if (showBtn && formWrap) {
  showBtn.addEventListener("click", function (event) {
    event.preventDefault();
    formWrap.style.display = "flex";
    showBtn.style.display = "none";
    firstInput.focus();
  });

  cancelBtn.addEventListener("click", function (event) {
    event.preventDefault();
    resetForm();
  });

  createBtn.addEventListener("click", createInteractor);

  [firstInput, lastInput].forEach(function (el) {
    el.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        createInteractor(event);
      }
    });
  });
}

function resetForm() {
  firstInput.value = "";
  lastInput.value = "";
  msg.textContent = "";
  formWrap.style.display = "none";
  showBtn.style.display = "";
}

function createInteractor(event) {
  event.preventDefault();
  const first = firstInput.value.trim();
  const last = lastInput.value.trim();
  if (first === "" && last === "") {
    msg.textContent = "Enter a name.";
    return;
  }

  createBtn.disabled = true;
  msg.textContent = "Creating…";

  const csrfField = document.querySelector('input[name="csrf_token"]');
  const body = new FormData();
  body.append("FirstName", first);
  body.append("LastName", last);
  if (csrfField) { body.append("csrf_token", csrfField.value); }

  fetch("/users/new.php", {
    method: "POST",
    credentials: "include",
    headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
    body: body,
  })
    .then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, data: data };
      });
    })
    .then(function (result) {
      createBtn.disabled = false;
      if (result.ok && result.data && result.data.ok) {
        addUserRow({ id: result.data.id, name: result.data.FullName });
        resetForm();
      } else {
        msg.textContent = (result.data && result.data.message) || "Could not create person.";
      }
    })
    .catch(function (error) {
      createBtn.disabled = false;
      msg.textContent = "Error: " + error.message;
    });
}
