import { API_BASE } from "./publicEnvironmentVariables.js";

// Search artifacts

function searchArtifacts(e) {
  let requestBody = {
    query: e.target.value,
    userid: e.srcElement.dataset.userid
  };
  fetch(API_BASE + "/artifacts.php", {
    method: "POST",
    credentials: "include",
    body: JSON.stringify(requestBody),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.authenticated == false) {
        location.href = "/login.php";
      } else {
        const searchResultsList = document.querySelector('ul.searchResults');
        searchResultsList.innerHTML = '';
        if (e.target.value.length === 0) {
          hideSearchResults();
        } else {
          showSearchResults();
          if (data.artifacts.length > 10) {
            var resultsListLength = 10;
          } else {
            var resultsListLength = data.artifacts.length;
          }
          for (let i = 0; i < resultsListLength; i++) {
            let listItem = document.createElement("li");
            listItem.value = data.artifacts[i].id;
            listItem.innerText = data.artifacts[i].Title;
            listItem.addEventListener('click', function() {
              document.querySelector('input#SearchTitleSubmission').value = data.artifacts[i].id;
              document.querySelector('input#SearchTitles').value = data.artifacts[i].Title;
              hideSearchResults();
            });
            searchResultsList.append(listItem);
          }
        }
      }
    });
}

const searchTitlesInput = document.querySelector("input#SearchTitles");

searchTitlesInput.addEventListener("input", searchArtifacts);

// Show and hide search results

function showSearchResults() {
  document.querySelector('div.searchResults').style.display = 'block';
}

searchTitlesInput.addEventListener('focus', showSearchResults);

function hideSearchResults() {
  document.querySelector('div.searchResults').style.display = 'none';
}

document.querySelector('#user0name').addEventListener('focus', hideSearchResults);
