(function () {
  const requestBtn = document.querySelector("#requestBggData");
  const statusEl = document.querySelector("#bggLookupStatus");
  const confirmEl = document.querySelector("#bggConfirm");
  const matchNameEl = document.querySelector("#bggMatchName");
  const matchYearEl = document.querySelector("#bggMatchYear");
  const matchYearWrap = document.querySelector("#bggMatchYearWrap");
  const matchLinkEl = document.querySelector("#bggMatchLink");
  const useBtn = document.querySelector("#bggUseMatch");
  const notThisBtn = document.querySelector("#bggNotThis");
  const othersEl = document.querySelector("#bggOtherMatches");
  const titleInput = document.querySelector("#Title");

  if (!requestBtn || !titleInput) {
    return;
  }

  let pending = null;
  let roster = [];

  requestBtn.addEventListener("click", function (event) {
    event.preventDefault();
    const name = titleInput.value.trim();
    if (name === "") {
      showStatus("Enter an item name first.");
      hideConfirm();
      return;
    }
    lookup("/artifacts/bgg-data?query=" + encodeURIComponent(name));
  });

  useBtn.addEventListener("click", function (event) {
    event.preventDefault();
    if (!pending || !pending.fields) {
      return;
    }
    fillForm(pending.fields);
    showStatus("Filled from " + pending.match.name + ".");
    hideConfirm();
  });

  notThisBtn.addEventListener("click", function (event) {
    event.preventDefault();
    const currentId = pending && pending.match ? pending.match.id : null;
    showAlternatives(roster.filter(function (alt) {
      return alt.id !== currentId;
    }));
  });

  function lookup(url) {
    requestBtn.disabled = true;
    hideConfirm();
    showStatus("Looking up BoardGameGeek…");

    fetch(url, { credentials: "include", headers: { Accept: "application/json" } })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        requestBtn.disabled = false;
        if (!result.ok || !result.data || !result.data.ok) {
          showStatus((result.data && result.data.error) || "Could not load BoardGameGeek data.");
          return;
        }
        pending = result.data;
        rememberRoster(result.data);
        showMatch(result.data);
        showStatus("Confirm this is the right game before filling the form.");
      })
      .catch(function (error) {
        requestBtn.disabled = false;
        showStatus("Could not load BoardGameGeek data. " + error.message);
      });
  }

  function showMatch(data) {
    const match = data.match || {};
    matchNameEl.textContent = match.name || "";
    if (match.year) {
      matchYearEl.textContent = match.year;
      matchYearWrap.hidden = false;
    } else {
      matchYearWrap.hidden = true;
    }
    matchLinkEl.href = match.url || "https://boardgamegeek.com/";
    othersEl.hidden = true;
    othersEl.innerHTML = "";
    const otherCount = roster.filter(function (alt) {
      return alt.id !== match.id;
    }).length;
    notThisBtn.hidden = otherCount === 0;
    confirmEl.hidden = false;
  }

  function rememberRoster(data) {
    if (!data.alternatives) {
      return;
    }
    const match = data.match || {};
    roster = [];
    if (match.id && match.name) {
      roster.push({ id: match.id, name: match.name });
    }
    data.alternatives.forEach(function (alt) {
      roster.push(alt);
    });
  }

  function showAlternatives(alternatives) {
    othersEl.innerHTML = "";
    if (!alternatives.length) {
      showStatus("No other BoardGameGeek matches for that name.");
      hideConfirm();
      return;
    }
    alternatives.forEach(function (alt) {
      const item = document.createElement("li");
      const button = document.createElement("button");
      button.type = "button";
      button.className = "bgg-secondary";
      button.textContent = alt.name;
      button.addEventListener("click", function (event) {
        event.preventDefault();
        lookup("/artifacts/bgg-data?objectid=" + encodeURIComponent(alt.id));
      });
      item.appendChild(button);
      othersEl.appendChild(item);
    });
    othersEl.hidden = false;
  }

  function fillForm(fields) {
    setField("Title", fields.Title);
    setField("SS", fields.SS);
    setField("MnP", fields.MnP);
    setField("MxP", fields.MxP);
    setField("MnT", fields.MnT);
    setField("MxT", fields.MxT);
    setField("age", fields.Age);
  }

  function setField(id, value) {
    if (value === undefined || value === null || value === "") {
      return;
    }
    const el = document.getElementById(id);
    if (el) {
      el.value = value;
    }
  }

  function showStatus(message) {
    statusEl.textContent = message;
    statusEl.hidden = false;
  }

  function hideConfirm() {
    confirmEl.hidden = true;
    othersEl.hidden = true;
    othersEl.innerHTML = "";
  }
})();
