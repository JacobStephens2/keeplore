---
name: keeplore
description: Use Keeplore's Agent HTTP API to find collection items, check what is due for use, look up uses or people, read proposal outcomes, or change whether an item is kept.
---

# Keeplore Agent API

Use the owner's agent key from `KEEPLORE_API_KEY` as a Bearer token for `https://api.keeplore.app`. The owner issues keys in [Settings → Agent API keys](https://keeplore.app/settings/agent-keys.php). Keep the key in the environment. The [public API guide](https://keeplore.app/api-docs) and [JSON catalog](https://keeplore.app/api-docs?format=json) define live endpoints, filters, and payloads.

## Read the collection

For known item IDs, run `python3 skills/keeplore/scripts/keeplore.py get 123` from the repository root. If the skill is installed separately, resolve `scripts/keeplore.py` relative to this file. Add `--out` to save full item records, including notes. For a narrow search, send `POST /artifacts.php` with the relevant filters. Resolve type IDs from the owner's `GET /types.php`. For an exhaustive read, start with `"cursor": null` and follow `next_cursor` until `has_more` is false. The maximum page size is 200.

Use one snapshot for a collection-wide review that also needs the use-by queue, proposal counts, or people.

```bash
snapshot_dir=$(mktemp -d)
python3 skills/keeplore/scripts/keeplore.py snapshot --out "$snapshot_dir/collection.json"
```

Add `--kept-only` when the item list should cover only the primary collection. Inspect the receipt and use the saved JSON for subsequent analysis. A snapshot contains private collection and people data. Store it outside the repository. The API allows 60 requests per 60 seconds per IP.

- For packing and similar plans, filter with `"kept": true` and `"physical": true`. Secondary collection membership is independent, and item tags are optional owner labels. Confirm details such as condition or portability from a relevant item record or the owner when they affect the recommendation.
- For use-by questions, read `GET /upcoming-interactions.php` or the snapshot's `upcoming.items`. The current endpoint covers dated kept items through its reported `horizon_days` and excludes items flagged To get rid of. For an item missing from the queue, check its acquisition date, last use, interaction frequency, and removal flag. Read the item detail when a field is absent from the collection response. See [Use-by date and App day](https://github.com/JacobStephens2/keeplore/blob/main/CONTEXT.md#use-by-date) for the calculation. Bulk `last_use` can reflect a legacy play even when `plays` is zero.
- For who used an item, query `GET /uses.php` by `artifact_id` or the person's `player_id` from `GET /players.php`. For proposal outcomes, query `GET /proposals.php`. Base use-by dates on the item's use history and acquisition date.

## Verify item references in plans

Cite each item in the form `` **Title** (`#123`) `` so the verifier checks both its title and ID. Run `python3 skills/keeplore/scripts/keeplore.py verify-markdown PLAN.md --snapshot "$snapshot_dir/collection.json"`. Check that the reported reference count matches the references you intended to verify and that the command exits successfully.

## Change Kept

The [agent key scope](https://github.com/JacobStephens2/keeplore/blob/main/docs/adr/0002-agent-access-http-read-kept-toggle.md) permits one write. When the owner requests a Kept change, resolve each item to its exact title and ID, then set the requested value with `set-kept --kept 0 123` or `set-kept --kept 1 123` on the helper. Report the result for each ID. If a batch stops partway through, read the current values before retrying. Route other requested changes to the Keeplore web UI and identify the item and intended edit.
