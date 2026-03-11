#!/usr/bin/env python3
"""Build Phase 2 training dataset JSONL from simple prompt/response inputs."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="inp", required=True, help="Input .jsonl or .csv with prompt,response columns")
    ap.add_argument("--out", default="training/phase2/omni_sft.jsonl", help="Output JSONL path")
    args = ap.parse_args()

    root = Path(__file__).resolve().parents[1]
    inp = Path(args.inp)
    if not inp.is_absolute():
        inp = (root / inp).resolve()
    out = Path(args.out)
    if not out.is_absolute():
        out = (root / out).resolve()

    out.parent.mkdir(parents=True, exist_ok=True)

    rows = []
    if inp.suffix.lower() == ".csv":
        with inp.open("r", encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            for r in reader:
                p = str(r.get("prompt") or r.get("input") or "").strip()
                a = str(r.get("response") or r.get("output") or "").strip()
                if p and a:
                    rows.append({"messages": [{"role": "user", "content": p}, {"role": "assistant", "content": a}]})
    else:
        for ln in inp.read_text(encoding="utf-8").splitlines():
            ln = ln.strip()
            if not ln:
                continue
            obj = json.loads(ln)
            if isinstance(obj.get("messages"), list):
                rows.append(obj)
                continue
            p = str(obj.get("prompt") or obj.get("input") or "").strip()
            a = str(obj.get("response") or obj.get("output") or "").strip()
            if p and a:
                rows.append({"messages": [{"role": "user", "content": p}, {"role": "assistant", "content": a}]})

    with out.open("w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")

    print(f"wrote {len(rows)} rows -> {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
