(function () {
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

  // Writes a match onto the Item form. The payload's keys are posted input
  // names. An input marked keep is skipped. An empty value clears an input
  // only when that input is marked replace. A picture input names its own
  // preview. Assigning a value does not fire input, so a match does not
  // count as the hand edit that drops the basis it just set.
  function apply(doc, fields) {
    var previews = [];
    Object.keys(fields || {}).forEach(function (id) {
      var input = doc.getElementById(id);
      if (!input || input.hasAttribute("data-bgg-keep")) {
        return;
      }
      var value = fields[id];
      var empty = value === undefined || value === null || value === "";
      if (empty && !input.hasAttribute("data-bgg-replace")) {
        return;
      }
      var written = empty ? "" : String(value);
      input.value = written;
      if (input.hasAttribute("data-bgg-preview")) {
        previews.push({ id: input.getAttribute("data-bgg-preview"), url: written });
      }
    });
    previews.forEach(function (preview) {
      var title = doc.getElementById("Title");
      showCover(doc.getElementById(preview.id), preview.url, title ? title.value : "");
    });
  }

  // A hand edit drops the inputs named on the edited field, and hides the
  // hints drawn from those inputs. The script does not list the ids.
  function bindHandEdits(doc) {
    doc.querySelectorAll("[data-bgg-clears]").forEach(function (input) {
      input.addEventListener("input", function () {
        (input.getAttribute("data-bgg-clears") || "").split(/\s+/).forEach(function (id) {
          if (id === "") {
            return;
          }
          var target = doc.getElementById(id);
          if (target) {
            target.value = "";
          }
          doc.querySelectorAll('[data-bgg-from="' + id + '"]').forEach(function (hint) {
            hint.hidden = true;
          });
        });
      });
    });
  }

  function fillBases(doc, bases) {
    doc.querySelectorAll("[data-bgg-basis]").forEach(function (hint) {
      var text = (bases && bases[hint.getAttribute("data-bgg-basis")]) || "";
      hint.textContent = text;
      hint.hidden = text === "";
    });
  }

  // Edit Item's Name is marked keep, so the status says the name was kept.
  function filledMessage(doc, matchName) {
    var title = doc.getElementById("Title");
    if (title && title.hasAttribute("data-bgg-keep")) {
      return "Filled from " + matchName + " and linked it. Save to keep the changes.";
    }
    return "Filled from " + matchName + ".";
  }

  if (typeof module !== "undefined" && module.exports) {
    module.exports = {
      apply: apply,
      bindHandEdits: bindHandEdits,
      showBases: fillBases,
      filledMessage: filledMessage,
    };
  }

  if (typeof document === "undefined") {
    return;
  }

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

  if (!requestBtn || !titleInput) {
    return;
  }

  bindHandEdits(document);
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
    apply(document, pending.fields);
    if (window.BggDefaultType) {
      window.BggDefaultType.apply(document, requestBtn);
    }
    showBases(pending.basis);
    showStatus(filledMessage(document, pending.match.name));
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
      matchSourceEl.textContent = match.site_name || "";
      matchSourceEl.hidden = false;
    } else {
      matchSourceEl.textContent = "";
      matchSourceEl.hidden = true;
    }
    matchLinkEl.href = match.url || "https://boardgamegeek.com/";
    matchLinkEl.textContent = "View on " + (match.site_name || "BoardGameGeek");
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
        site_name: match.site_name || "",
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

  // Replace each field hint with what the chosen match rests on.
  function showBases(bases) {
    fillBases(document, bases);
  }

  function alternativeLabel(alt) {
    let label = alt.name || "";
    if (alt.year) {
      label += " (" + alt.year + ")";
    }
    if (alt.source && alt.source !== "BGG") {
      label += " · " + (alt.site_name || "");
    }
    return label;
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
