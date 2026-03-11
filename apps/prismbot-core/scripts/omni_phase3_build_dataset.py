#!/usr/bin/env python3
"""Build Phase 3 dataset by merging base SFT + runtime feedback examples.

Sources:
- training/phase2/omni_sft.jsonl (base)
- training/phase3/feedback.jsonl (manual high-signal examples)
- data/omni-runs.json (text outputs from successful orchestrations)
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Iterable


def norm(t: str, max_len: int = 700) -> str:
    t = re.sub(r"\s+", " ", str(t or "").strip())
    return t[:max_len]


def row(user: str, assistant: str) -> dict:
    return {
        "messages": [
            {"role": "user", "content": norm(user)},
            {"role": "assistant", "content": norm(assistant)},
        ]
    }


def read_jsonl(path: Path) -> Iterable[dict]:
    if not path.exists():
        return []
    out = []
    for ln in path.read_text(encoding="utf-8").splitlines():
        ln = ln.strip()
        if not ln:
            continue
        try:
            obj = json.loads(ln)
        except Exception:
            continue
        out.append(obj)
    return out


def extract_rows_from_messages(obj: dict) -> list[dict]:
    rows: list[dict] = []
    msgs = obj.get("messages")
    if not isinstance(msgs, list):
        return rows
    user = ""
    assistant = ""
    for m in msgs:
        role = str((m or {}).get("role", "")).lower()
        content = str((m or {}).get("content", "")).strip()
        if role == "user" and content:
            user = content
        elif role == "assistant" and content:
            assistant = content
    if user and assistant:
        rows.append(row(user, assistant))
    return rows


def extract_rows_from_runs(path: Path, limit: int = 60) -> list[dict]:
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return []
    jobs = (data.get("jobs") or {}).values()
    out: list[dict] = []
    for j in jobs:
        if str(j.get("status", "")).lower() != "completed":
            continue
        prompt = norm(j.get("prompt", ""))
        if not prompt:
            continue
        for step in (j.get("executedSteps") or []):
            if str(step.get("type", "")) != "text":
                continue
            text = norm(step.get("output", ""))
            if not text:
                continue
            text = re.sub(r"^PrismBot AI:\s*", "", text, flags=re.I)
            out.append(row(prompt, text))
            if len(out) >= limit:
                return out
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="training/phase2/omni_sft.jsonl")
    ap.add_argument("--feedback", default="training/phase3/feedback.jsonl")
    ap.add_argument("--runs", default="data/omni-runs.json")
    ap.add_argument("--out", default="training/phase3/omni_sft_phase3.jsonl")
    ap.add_argument("--max-runs", type=int, default=60)
    args = ap.parse_args()

    root = Path(__file__).resolve().parents[1]
    base = (root / args.base).resolve()
    feedback = (root / args.feedback).resolve()
    runs = (root / args.runs).resolve()
    out = (root / args.out).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)

    rows: list[dict] = []

    for obj in read_jsonl(base):
        rows.extend(extract_rows_from_messages(obj))
    for obj in read_jsonl(feedback):
        rows.extend(extract_rows_from_messages(obj))
    rows.extend(extract_rows_from_runs(runs, limit=max(0, args.max_runs)))

    # De-dupe by pair key
    seen = set()
    deduped = []
    for r in rows:
        u = r["messages"][0]["content"]
        a = r["messages"][1]["content"]
        key = (u.lower(), a.lower())
        if key in seen:
            continue
        seen.add(key)
        deduped.append(r)

    with out.open("w", encoding="utf-8") as f:
        for r in deduped:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")

    print(f"wrote {len(deduped)} rows -> {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
