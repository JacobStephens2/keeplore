(function () {
  const requestBtn = document.querySelector("#requestBggData");
  const statusEl = document.querySelector("#bggLookupStatus");
  const confirmEl = document.querySelector("#bggConfirm");
  const matchNameEl = document.querySelector("#bggMatchName");
  const matchYearEl = document.querySelector("#bggMatchYear");
  const matchYearWrap = document.querySelector("#bggMatchYearWrap");
  const matchSourceEl = document.querySelector("#bggMatchSource");
  const matchLinkEl = document.querySelector("#bggMatchLink");
  const matchImageEl = document.querySelector("#bggMatchImage");
  const useBtn = document.querySelector("#bggUseMatch");
  const othersEl = document.querySelector("#bggOtherMatches");
  const titleInput = document.querySelector("#Title");
  const pictureInput = document.querySelector("#image_url");
  const picturePreviewEl = document.querySelector("#itemPicturePreview");

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
    showCover(matchImageEl, match.image, match.name);
    matchNameEl.textContent = match.name || "";
    if (match.year) {
      matchYearEl.textContent = match.year;
      matchYearWrap.hidden = false;
    } else {
      matchYearWrap.hidden = true;
    }
    if (match.source && match.source !== "BGG") {
      matchSourceEl.textContent = match.source;
      matchSourceEl.hidden = false;
    } else {
      matchSourceEl.textContent = "";
      matchSourceEl.hidden = true;
    }
    matchLinkEl.href = match.url || "https://boardgamegeek.com/";
    matchLinkEl.textContent = "View on " + sourceSiteName(match.source);
    showAlternatives(roster.filter(function (alt) {
      return alt.id !== match.id;
    }));
    confirmEl.hidden = false;
  }

  function rememberRoster(data) {
    if (!data.alternatives) {
      return;
    }
    const match = data.match || {};
    roster = [];
    if (match.id && match.name) {
      roster.push({
        id: match.id,
        name: match.name,
        year: match.year || "",
        source: match.source || "BGG",
      });
    }
    data.alternatives.forEach(function (alt) {
      roster.push(alt);
    });
  }

  function showAlternatives(alternatives) {
    othersEl.innerHTML = "";
    if (!alternatives.length) {
      othersEl.hidden = true;
      return;
    }
    alternatives.forEach(function (alt) {
      const item = document.createElement("li");
      const button = document.createElement("button");
      button.type = "button";
      button.className = "bgg-secondary";
      button.textContent = alternativeLabel(alt);
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
    setField("Yr", fields.Yr);
    setPicture(fields.image_url);
  }

  function showCover(img, url, name) {
    if (!img) {
      return;
    }
    if (!url) {
      img.removeAttribute("src");
      img.alt = "";
      img.hidden = true;
      return;
    }
    img.src = url;
    img.alt = name ? name + " cover" : "Game cover";
    img.hidden = false;
  }

  function setPicture(url) {
    if (!pictureInput || !picturePreviewEl) {
      return;
    }
    showCover(picturePreviewEl, url, titleInput.value);
    pictureInput.value = url || "";
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

  function alternativeLabel(alt) {
    let label = alt.name || "";
    if (alt.year) {
      label += " (" + alt.year + ")";
    }
    if (alt.source && alt.source !== "BGG") {
      label += " · " + alt.source;
    }
    return label;
  }

  function sourceSiteName(source) {
    if (source === "RPGG") {
      return "RPGGeek";
    }
    if (source === "VGG") {
      return "VideoGameGeek";
    }
    return "BoardGameGeek";
  }

  function showStatus(message) {
    statusEl.textContent = message;
    statusEl.hidden = false;
  }

  function hideConfirm() {
    confirmEl.hidden = true;
    othersEl.hidden = true;
    othersEl.innerHTML = "";
    showCover(matchImageEl, "", "");
  }
})();
