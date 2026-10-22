import { itemPick } from "./searchArtifactsList.js";

// Lets you create a brand-new item without leaving the page.
// On success the new item is selected in the search box, mirroring a search-result click.

const showBtn = document.querySelector("#showNewEntity");
const formWrap = document.querySelector("#newEntityForm");
const titleInput = document.querySelector("#newEntityTitle");
const createBtn = document.querySelector("#createEntity");
const cancelBtn = document.querySelector("#cancelNewEntity");
const msg = document.querySelector("#newEntityMsg");

if (showBtn && formWrap) {
  showBtn.addEventListener("click", function (event) {
    event.preventDefault();
    formWrap.style.display = "flex";
    showBtn.style.display = "none";
    titleInput.focus();
  });

  cancelBtn.addEventListener("click", function (event) {
    event.preventDefault();
    resetForm();
  });

  createBtn.addEventListener("click", createEntity);

  titleInput.addEventListener("keydown", function (event) {
    if (event.key === "Enter") {
      event.preventDefault();
      createEntity(event);
    }
  });
}

function resetForm() {
  titleInput.value = "";
  msg.textContent = "";
  formWrap.style.display = "none";
  showBtn.style.display = "";
}

function selectEntity(id, title) {
  itemPick.select(id, title);
}

function createEntity(event) {
  event.preventDefault();
  const title = titleInput.value.trim();
  if (title === "") {
    msg.textContent = "Enter an item name.";
    return;
  }

  createBtn.disabled = true;
  msg.textContent = "Creating…";

  const csrfField = document.querySelector('input[name="csrf_token"]');
  const body = new FormData();
  body.append("Title", title);
  if (csrfField) { body.append("csrf_token", csrfField.value); }

  fetch("/artifacts/new.php", {
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
        selectEntity(result.data.id, result.data.Title);
        resetForm();
      } else {
        msg.textContent = (result.data && result.data.message) || "Could not create item.";
      }
    })
    .catch(function (error) {
      createBtn.disabled = false;
      msg.textContent = "Error: " + error.message;
    });
}
