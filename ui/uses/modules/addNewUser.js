import { addUserRow } from "./userRows.js";

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

// The record form owns this create. Another form on the page has its own token.
function recordFormToken() {
  const form = firstInput.closest ? firstInput.closest("form") : null;
  const field = form ? form.querySelector('input[name="csrf_token"]') : null;
  return field ? field.value : "";
}

function createInteractor(event) {
  if (event && typeof event.preventDefault === "function") {
    event.preventDefault();
  }
  globalThis.PersonPick.createPerson({
    url: "/users/new.php",
    first: firstInput,
    last: lastInput,
    button: createBtn,
    message: msg,
    token: recordFormToken,
    fetch: fetch,
    onSuccess: function (id, fullName) {
      addUserRow(document, { id: id, name: fullName });
      resetForm();
    },
  });
}
