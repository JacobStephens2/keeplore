---
name: keeplore
description: Read a Keeplore collection, use-by queue, uses, people, and proposal outcomes, or toggle whether an item is kept over the Agent HTTP API (KEEPLORE_API_KEY).
---

# Keeplore Agent API

Use the bundled zero-dependency Python 3 helper (`scripts/keeplore.py`, resolved relative to this skill directory) to query `https://api.keeplore.app` (or `KEEPLORE_API_URL`) with `KEEPLORE_API_KEY` (`ak_…`). Live endpoint contract: `https://keeplore.app/api-docs?format=json`.

## 1. Snapshot the collection

Verify that `KEEPLORE_API_KEY` is present in the environment without printing its value, then dump a complete local snapshot in one cursor pass:

```bash
python3 /path/to/skills/keeplore/scripts/keeplore.py snapshot \
  --out /tmp/keeplore-snapshot.json
```

`snapshot` pages `POST /artifacts.php` (`cursor` mode, `per_page: 200`, `fields: "collection"`, `include: ["uses_summary"]`) and fetches `GET /types.php`, `GET /upcoming-interactions.php`, `GET /proposals.php?include_other=1`, and `GET /players.php` in ~25 requests (well under the 60 requests / 60 seconds per-IP rate limit). Answer collection, play-count, tag, and curation questions from the saved JSON file rather than re-querying the API.

**Completion:** `/tmp/keeplore-snapshot.json` exists and the receipt reports total items, kept items (`is_kept == 1`), use-by queue counts (`past_due`, `due_today`, `due_soon`, `upcoming`), and any duplicate titles.

## 2. Reconcile the use-by queue and single-item fields

Cross-reference the bulk item list against the use-by queue (`upcoming.items`) using these three rules:

1. **Use-by date math (`America/New_York` app day):**
   - When `last_use` is `null` or earlier than `Acq`: `use_by_date = Acq + interval_days`.
   - When `last_use >= Acq`: `use_by_date = last_use + (2 × interval_days)`. The default `182.5`-day interval gives a **365-day** window after `last_use`; a `365`-day interval gives a **730-day (2-year)** window after `last_use`.
2. **Bulk list vs. single-item fields:**
   - `POST /artifacts.php` returns `id`, `Title`, `type`, `type_id`, `is_kept`, `is_physical`, `is_digital`, `is_in_secondary_collection`, `tags`, `MnP`, `MxP`, `SS`, `MnT`, `MxT`, `Wt`, `Yr`, `Acq`, `plays`, and `last_use`.
   - `to_get_rid_of`, `interaction_frequency_days`, `snoozed_until`, and `Notes` are returned only by `GET /artifact.php?id=<id>`.
3. **Hidden kept items (`is_kept = 1` absent from `/upcoming-interactions.php`):**
   - `/upcoming-interactions.php` lists kept items due within 60 days **excluding** items flagged `to_get_rid_of = 1`.
   - Any kept item (`is_kept = 1`) whose `last_use` (or `Acq` when `plays == 0`) is older than `today - (2 × default_interval_days)` yet absent from `upcoming.items` has either `to_get_rid_of = 1`, a custom `interaction_frequency_days > default_interval_days`, or an active `snoozed_until`. Inspect specific candidates in batches with `get`:

```bash
python3 /path/to/skills/keeplore/scripts/keeplore.py get 1830 3038 4028
```

**Completion:** Every kept item in scope is classified as active (within its interval), due/overdue in `upcoming.items`, or excluded from `upcoming.items` (`to_get_rid_of = 1`, custom `interaction_frequency_days`, or `snoozed_until`).

## 3. Toggle kept or report UI-only edits

Agent keys have scope **reads plus the kept toggle** (`ADR-0002`):

- **Flip `is_kept` (`0` or `1`)** via `POST /artifact-kept.php` after confirming the target item IDs with the user:
  ```bash
  python3 /path/to/skills/keeplore/scripts/keeplore.py set-kept --kept 0 1830 1951
  ```
- **All other mutations return `403` for agent keys:** changing `interaction_frequency_days`, `to_get_rid_of`, `type`, `is_physical`, `is_digital`, `is_in_secondary_collection`, or `tags`, recording a use, or merging duplicate items (`/artifacts/edit.php?id=<survivor_id>` -> *Merge another item into this one*) must be listed with exact item `#id` and `Title` for the owner to apply in the web UI.

**Completion:** Every requested `is_kept` change is confirmed by a `200` response from `/artifact-kept.php`, and every UI-only change names the exact `#id`, `Title`, and target field value.

## 4. Verify item IDs in written plans

When writing or updating a Markdown document that cites Keeplore items (such as `**Title** (\`#123\`)` or `\`#123 Title\``), run `verify-markdown` against the snapshot before finishing:

```bash
python3 /path/to/skills/keeplore/scripts/keeplore.py verify-markdown \
  /path/to/plan.md --snapshot /tmp/keeplore-snapshot.json
```

**Completion:** `verify-markdown` exits `0` with zero missing IDs, zero title mismatches, and zero unintended `is_kept = 0` items.

## Domain vocabulary

Use Keeplore's canonical terms from `CONTEXT.md`:

- **Item**: any tracked possession (`table game`, `book`, `clothing`, `equipment`, `dance`, `instrument`, etc.).
- **Kept** (`is_kept`): chosen for the primary collection. Independent of **Secondary collection** (`is_in_secondary_collection`), **Physical item** (`is_physical`), **Digital item** (`is_digital`), and **To get rid of** (`to_get_rid_of`).
- **Use** (`plays`, `last_use`): an actual recorded interaction that restarts time since last use.
- **Item proposal** (`explicit_declines`, `chose_something_else`): a suggestion to use an item. Unsuccessful proposals never count as uses and never change `last_use` or `use_by_date`.
