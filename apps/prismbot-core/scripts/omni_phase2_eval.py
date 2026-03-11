#!/usr/bin/env python3
"""Simple Phase 2 eval runner for Omni local model endpoint."""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.request
from pathlib import Path


def post_json(url: str, token: str, payload: dict, timeout: int = 120):
    req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), method="POST")
    req.add_header("content-type", "application/json")
    req.add_header("authorization", f"Bearer {token}")
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-url", default=os.getenv("OMNI_BASE_URL", "http://127.0.0.1:8799/api/omni"))
    ap.add_argument("--token", default=os.getenv("PRISMBOT_API_TOKEN", ""))
    ap.add_argument("--out", default="docs/evidence/phase2-eval.json")
    ap.add_argument("--request-timeout", type=int, default=75)
    args = ap.parse_args()

    token = args.token.strip()
    if not token:
        env_file = Path.home() / ".config" / "prismbot-core.env"
        if env_file.exists():
            for ln in env_file.read_text(encoding="utf-8").splitlines():
                if ln.startswith("PRISMBOT_API_TOKEN="):
                    token = ln.split("=", 1)[1].strip()
                    break
    if not token:
        raise SystemExit("missing token (set PRISMBOT_API_TOKEN or ~/.config/prismbot-core.env)")

    tests = [
        "In one sentence, explain what Omni is.",
        "Give exactly 3 bullet points for hardening a Linux server.",
        "Rewrite this cleaner: we need this done asap but no downtime",
        "What is 19*23? respond with just the number.",
    ]

    results = []
    endpoint = args.base_url.rstrip("/") + "/chat/completions"
    for prompt in tests:
        t0 = time.time()
        payload = {"messages": [{"role": "user", "content": prompt}]}
        err = None
        resp = {}
        try:
            resp = post_json(endpoint, token, payload, timeout=max(5, args.request_timeout))
        except Exception as exc:
            err = str(exc)
        dt = int((time.time() - t0) * 1000)
        text = ""
        if isinstance(resp, dict):
            text = str(((resp.get("choices") or [{}])[0].get("message", {}) or {}).get("content", ""))
        results.append({
            "prompt": prompt,
            "ok": bool(resp.get("ok")) if isinstance(resp, dict) else False,
            "model": (resp.get("model") if isinstance(resp, dict) else None),
            "backend": (resp.get("backend") if isinstance(resp, dict) else None),
            "latencyMs": dt,
            "output": text,
            "error": err,
        })

    report = {
        "ts": int(time.time() * 1000),
        "baseUrl": args.base_url,
        "count": len(results),
        "avgLatencyMs": int(sum(r["latencyMs"] for r in results) / max(1, len(results))),
        "results": results,
    }

    out = Path(args.out)
    if not out.is_absolute():
        out = (Path(__file__).resolve().parents[1] / out).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {out}")
    print(f"avg latency: {report['avgLatencyMs']}ms")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
