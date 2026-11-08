#!/usr/bin/env python3
"""Zero-dependency CLI helper for the Keeplore Agent HTTP API."""

import argparse
from collections import Counter, defaultdict
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_BASE_URL = "https://api.keeplore.app"
USER_AGENT = "Keeplore-Agent-Skill/1.0"


def api_config() -> tuple[str, str]:
    key = os.environ.get("KEEPLORE_API_KEY", "").strip()
    if not key:
        sys.exit("Error: KEEPLORE_API_KEY environment variable is not set.")
    base = os.environ.get("KEEPLORE_API_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    return base, key


def request_json(
    base_url: str,
    api_key: str,
    path: str,
    method: str = "GET",
    body: dict | None = None,
) -> dict:
    url = f"{base_url}{path}"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Accept": "application/json",
        "User-Agent": USER_AGENT,
    }
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode("utf-8")

    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        err_body = exc.read().decode("utf-8", errors="replace")
        sys.exit(f"HTTP {exc.code} on {method} {path}: {err_body}")


def cmd_snapshot(args: argparse.Namespace) -> None:
    base_url, api_key = api_config()
    items: list[dict] = []
    cursor = None
    pages = 0

    while True:
        payload: dict = {
            "cursor": cursor,
            "per_page": 200,
            "fields": "collection",
            "include": ["uses_summary"],
        }
        if args.kept_only:
            payload["kept"] = True
        page = request_json(base_url, api_key, "/artifacts.php", method="POST", body=payload)
        batch = page.get("artifacts", [])
        items.extend(batch)
        pages += 1
        if not page.get("has_more"):
            break
        cursor = page.get("next_cursor")

    types_resp = request_json(base_url, api_key, "/types.php")
    upcoming_resp = request_json(base_url, api_key, "/upcoming-interactions.php")
    proposals_resp = request_json(base_url, api_key, "/proposals.php?include_other=1")
    players_resp = request_json(base_url, api_key, "/players.php")

    snapshot = {
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "base_url": base_url,
        "types": types_resp.get("types", []),
        "upcoming": upcoming_resp,
        "proposals": proposals_resp,
        "players": players_resp.get("players", []),
        "items": items,
    }

    out_path = os.path.abspath(args.out)
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(snapshot, fh, indent=2)

    kept_items = [i for i in items if i.get("is_kept") == 1]
    upcoming_items = upcoming_resp.get("items", [])
    status_counts = Counter(u.get("status") for u in upcoming_items)
    type_counts = Counter(i.get("type") for i in kept_items)

    by_title: defaultdict[str, list[dict]] = defaultdict(list)
    for item in items:
        norm = (item.get("Title") or "").strip().lower()
        if norm:
            by_title[norm].append(item)
    kept_duplicates = {
        t: rows
        for t, rows in by_title.items()
        if len(rows) > 1 and any(r.get("is_kept") == 1 for r in rows)
    }

    print(f"Snapshot written to: {out_path}")
    print(f"Pages fetched: {pages} (+ 4 metadata endpoints)")
    print(f"Total items: {len(items)} | Kept (is_kept=1): {len(kept_items)}")
    print(f"Kept by type (top 10): { dict(type_counts.most_common(10)) }")
    print(
        f"Use-by queue ({len(upcoming_items)} items, default_interval={upcoming_resp.get('default_interval_days')}d): "
        f"{dict(status_counts)}"
    )
    if kept_duplicates:
        print(f"Duplicate titles with >=1 kept row ({len(kept_duplicates)}):")
        for title, rows in sorted(kept_duplicates.items()):
            summary = ", ".join(
                f"#{r['id']} (type={r.get('type')}, is_kept={r.get('is_kept')}, plays={r.get('plays')})"
                for r in rows
            )
            print(f"  {title!r}: {summary}")


def cmd_get(args: argparse.Namespace) -> None:
    base_url, api_key = api_config()
    results = []
    for idx, item_id in enumerate(args.ids):
        if idx > 0 and idx % 50 == 0:
            time.sleep(61)
        resp = request_json(base_url, api_key, f"/artifact.php?id={int(item_id)}")
        art = resp.get("artifact", {})
        results.append(art)
        print(
            f"#{art.get('id')} {art.get('Title')} [{art.get('type_name') or art.get('type')}] | "
            f"is_kept={art.get('is_kept')} to_get_rid_of={art.get('to_get_rid_of')} "
            f"freq={art.get('interaction_frequency_days')} snoozed={art.get('snoozed_until')} "
            f"phys={art.get('is_physical')} dig={art.get('is_digital')} sec={art.get('is_in_secondary_collection')} "
            f"Acq={art.get('Acq')} tags={art.get('tags')}"
        )
    if args.out:
        with open(args.out, "w", encoding="utf-8") as fh:
            json.dump(results, fh, indent=2)


def cmd_set_kept(args: argparse.Namespace) -> None:
    base_url, api_key = api_config()
    kept_val = int(args.kept)
    for idx, item_id in enumerate(args.ids):
        if idx > 0 and idx % 50 == 0:
            time.sleep(61)
        resp = request_json(
            base_url,
            api_key,
            "/artifact-kept.php",
            method="POST",
            body={"id": int(item_id), "is_kept": kept_val},
        )
        art = resp.get("artifact", {})
        print(f"Updated #{art.get('id', item_id)}: is_kept={art.get('is_kept', kept_val)}")


def cmd_verify_markdown(args: argparse.Namespace) -> None:
    with open(args.snapshot, encoding="utf-8") as fh:
        snap = json.load(fh)
    items_by_id = {int(i["id"]): i for i in snap.get("items", [])}

    with open(args.markdown_file, encoding="utf-8") as fh:
        text = fh.read()

    bold_refs = re.findall(r"\*\*([^*]+)\*\*\s*\(`#(\d+)`", text)
    inline_refs = re.findall(r"`#(\d+)\s+([^`]+)`", text)

    errors: list[str] = []
    checked = 0

    for title, id_str in bold_refs:
        if title.strip().lstrip("~").isdigit():
            continue
        checked += 1
        aid = int(id_str)
        item = items_by_id.get(aid)
        if not item:
            errors.append(f"MISSING ID #{aid} for bold reference {title!r}")
            continue
        real_title = item.get("Title") or ""
        norm_ref = re.sub(r"[^a-z0-9]+", " ", title.lower()).strip()
        norm_real = re.sub(r"[^a-z0-9]+", " ", real_title.lower()).strip()
        ref_tokens = set(norm_ref.split())
        real_tokens = set(norm_real.split())
        if norm_ref not in norm_real and norm_real not in norm_ref and not (ref_tokens and ref_tokens.issubset(real_tokens)):
            errors.append(f"TITLE MISMATCH #{aid}: markdown={title!r} vs keeplore={real_title!r}")
        if not args.allow_unkept and item.get("is_kept") == 0 and aid not in set(args.allow_unkept_ids or []):
            errors.append(f"UNKEPT ITEM #{aid}: {real_title!r} has is_kept=0 in snapshot")

    for id_str, title in inline_refs:
        checked += 1
        aid = int(id_str)
        item = items_by_id.get(aid)
        if not item:
            errors.append(f"MISSING ID #{aid} for inline reference {title!r}")
            continue
        if not args.allow_unkept and item.get("is_kept") == 0 and aid not in set(args.allow_unkept_ids or []):
            errors.append(f"UNKEPT ITEM #{aid}: {item.get('Title')!r} has is_kept=0 in snapshot")

    print(f"Checked {checked} item references in {args.markdown_file}.")
    if errors:
        for err in errors:
            print(f"  ERROR: {err}")
        sys.exit(1)
    print("OK: All item IDs and titles match the Keeplore snapshot.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Keeplore Agent HTTP API helper")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_snap = sub.add_parser("snapshot", help="Fetch full collection, use-by queue, proposals, types, and players")
    p_snap.add_argument("--out", required=True, help="Output JSON path")
    p_snap.add_argument("--kept-only", action="store_true", help="Fetch only kept items (is_kept=1)")
    p_snap.set_defaults(func=cmd_snapshot)

    p_get = sub.add_parser("get", help="Fetch single-item records via GET /artifact.php?id=")
    p_get.add_argument("ids", nargs="+", type=int, help="Item IDs")
    p_get.add_argument("--out", help="Optional JSON output path")
    p_get.set_defaults(func=cmd_get)

    p_kept = sub.add_parser("set-kept", help="Toggle is_kept via POST /artifact-kept.php")
    p_kept.add_argument("--kept", required=True, choices=["0", "1"], help="Target is_kept value (0 or 1)")
    p_kept.add_argument("ids", nargs="+", type=int, help="Item IDs")
    p_kept.set_defaults(func=cmd_set_kept)

    p_ver = sub.add_parser("verify-markdown", help="Verify #ID and Title references in a Markdown file against a snapshot")
    p_ver.add_argument("markdown_file", help="Path to Markdown file")
    p_ver.add_argument("--snapshot", required=True, help="Path to snapshot JSON file")
    p_ver.add_argument("--allow-unkept", action="store_true", help="Do not fail on items with is_kept=0")
    p_ver.add_argument("--allow-unkept-ids", nargs="*", type=int, default=[], help="Specific unkept item IDs to allow")
    p_ver.set_defaults(func=cmd_verify_markdown)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
