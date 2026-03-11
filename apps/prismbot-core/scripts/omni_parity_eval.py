#!/usr/bin/env python3
"""Omni parity benchmark runner (v1).

Runs a weighted benchmark pack against Omni endpoints and writes a JSON report.
"""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Tuple


ENDPOINT_MAP = {
    "chat": ("POST", "/chat/completions"),
    "rewrite": ("POST", "/rewrite"),
    "summarize": ("POST", "/summarize"),
    "reason": ("POST", "/reason"),
    "code_generate": ("POST", "/code/generate"),
    "code_explain": ("POST", "/code/explain"),
    "code_fix": ("POST", "/code/fix"),
    "code_test": ("POST", "/code/test"),
    "extract": ("POST", "/extract"),
    "classify": ("POST", "/classify"),
    "moderate": ("POST", "/moderate"),
    "images_generate": ("POST", "/images/generate"),
    "images_edit": ("POST", "/images/edit"),
    "audio_speak": ("POST", "/audio/speak"),
    "video_generate": ("POST", "/video/generate"),
    "video_keyframes": ("POST", "/video/keyframes"),
    "orchestrate_plan": ("POST", "/orchestrate/plan"),
    "orchestrate_run": ("POST", "/orchestrate/run"),
    "orchestrate_jobs": ("GET", "/orchestrate/jobs"),
}


def read_token(explicit: str) -> str:
    token = explicit.strip()
    if token:
        return token
    token = os.getenv("PRISMBOT_API_TOKEN", "").strip()
    if token:
        return token
    env_file = Path.home() / ".config" / "prismbot-core.env"
    if env_file.exists():
        for ln in env_file.read_text(encoding="utf-8").splitlines():
            if ln.startswith("PRISMBOT_API_TOKEN="):
                return ln.split("=", 1)[1].strip()
    return ""


def request_json(method: str, url: str, token: str, payload: Dict[str, Any] | None, timeout: int = 90) -> Tuple[int, Dict[str, Any], str | None]:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("content-type", "application/json")
    if token:
        req.add_header("authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            body = r.read().decode("utf-8", errors="replace")
            parsed = json.loads(body) if body.strip() else {}
            return int(getattr(r, "status", 200)), parsed, None
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace") if hasattr(e, "read") else ""
        try:
            parsed = json.loads(body) if body.strip() else {}
        except Exception:
            parsed = {"raw": body}
        return int(e.code or 500), parsed, f"HTTP {e.code}"
    except Exception as e:
        return 0, {}, str(e)


def build_payload(test: Dict[str, Any]) -> Dict[str, Any] | None:
    t = test["type"]
    txt = test.get("input", "")
    if t == "chat":
        return {"messages": test.get("messages", [{"role": "user", "content": txt}])}
    if t in {"rewrite", "summarize", "reason", "extract", "classify", "moderate", "code_generate", "code_explain", "code_fix", "code_test"}:
        return {"input": txt, "text": txt, "prompt": txt, "messages": [{"role": "user", "content": txt}]}
    if t == "images_generate":
        return {"prompt": txt, "size": 512, "transparent": True}
    if t == "images_edit":
        return {"prompt": txt}
    if t == "audio_speak":
        return {"text": txt or "Omni is online."}
    if t == "video_generate":
        return {"prompt": txt}
    if t == "video_keyframes":
        return {"path": txt}
    if t == "orchestrate_plan":
        return {"prompt": txt}
    if t == "orchestrate_run":
        return {"prompt": txt, "async": False}
    if t == "orchestrate_jobs":
        return None
    return {"input": txt}


def flatten_text(resp: Dict[str, Any]) -> str:
    parts: List[str] = []
    if not isinstance(resp, dict):
        return ""
    choices = resp.get("choices")
    if isinstance(choices, list) and choices:
        msg = (choices[0] or {}).get("message", {})
        if isinstance(msg, dict):
            content = msg.get("content")
            if isinstance(content, str):
                parts.append(content)

    data = resp.get("data")
    if isinstance(data, dict):
        for key in ("text", "output", "result", "summary", "answer", "rewritten"):
            v = data.get(key)
            if isinstance(v, str):
                parts.append(v)
        if isinstance(data.get("steps"), list):
            parts.extend([str(x) for x in data.get("steps") if isinstance(x, (str, dict, list))])

    for key in ("text", "output", "message", "answer", "summary", "rewritten", "label"):
        v = resp.get(key)
        if isinstance(v, str):
            parts.append(v)

    if isinstance(resp.get("steps"), list):
        parts.extend([str(x) for x in resp.get("steps")])
    if isinstance(resp.get("plannedSteps"), list):
        parts.extend([str(x) for x in resp.get("plannedSteps")])
    if isinstance(resp.get("executedSteps"), list):
        parts.extend([str(x) for x in resp.get("executedSteps")])

    return "\n".join([p for p in parts if p]).strip()


def get_path(obj: Dict[str, Any], path: str) -> Any:
    cur: Any = obj
    for key in path.split('.'):
        if isinstance(cur, dict) and key in cur:
            cur = cur[key]
        else:
            return None
    return cur


def check_expect(expect: Dict[str, Any], ok: bool, text: str, raw: str, error: str | None, resp: Dict[str, Any], status: int) -> Tuple[bool, List[str]]:
    reasons: List[str] = []
    passed = True

    if expect.get("okOnly") and not ok:
        passed = False
        reasons.append("response not ok")

    status_equals = expect.get("statusEquals")
    if isinstance(status_equals, int) and status != status_equals:
        passed = False
        reasons.append(f"status {status} != {status_equals}")

    status_in = expect.get("statusIn")
    if isinstance(status_in, list) and status not in status_in:
        passed = False
        reasons.append(f"status {status} not in {status_in}")

    if "contains" in expect and expect["contains"] not in (text + "\n" + raw):
        passed = False
        reasons.append(f"missing contains='{expect['contains']}'")

    contains_any = expect.get("containsAny")
    if isinstance(contains_any, list) and contains_any:
        hay = text + "\n" + raw
        if not any(str(x) in hay for x in contains_any):
            passed = False
            reasons.append("missing any required token")

    min_chars = expect.get("minChars")
    if isinstance(min_chars, int) and len(text) < min_chars:
        passed = False
        reasons.append(f"text too short ({len(text)} < {min_chars})")

    max_chars = expect.get("maxChars")
    if isinstance(max_chars, int) and len(text) > max_chars:
        passed = False
        reasons.append(f"text too long ({len(text)} > {max_chars})")

    has_keys = expect.get("hasKeys")
    if isinstance(has_keys, list):
        missing = [k for k in has_keys if k not in resp]
        if missing:
            passed = False
            reasons.append(f"missing keys: {', '.join(missing)}")

    exists_paths = expect.get("existsPaths")
    if isinstance(exists_paths, list):
        missing_paths = [p for p in exists_paths if get_path(resp, str(p)) is None]
        if missing_paths:
            passed = False
            reasons.append(f"missing paths: {', '.join(missing_paths)}")

    equals = expect.get("equals")
    if isinstance(equals, dict):
        for p, expected in equals.items():
            actual = get_path(resp, str(p))
            if actual != expected:
                passed = False
                reasons.append(f"path {p} expected {expected!r} got {actual!r}")

    if error and not expect.get("allowFail"):
        # Some contract tests intentionally expect non-200 responses.
        status_equals = expect.get("statusEquals")
        status_in = expect.get("statusIn")
        status_expected = (
            (isinstance(status_equals, int) and status == status_equals)
            or (isinstance(status_in, list) and status in status_in)
        )
        if not status_expected:
            passed = False
            reasons.append(error)

    return passed, reasons


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pack", default="benchmarks/omni-parity-pack.v1.json")
    ap.add_argument("--base-url", default="")
    ap.add_argument("--token", default="")
    ap.add_argument("--out", default="docs/evidence/omni-parity-report.json")
    ap.add_argument("--timeout", type=int, default=90)
    args = ap.parse_args()

    repo = Path(__file__).resolve().parents[1]
    pack_path = Path(args.pack)
    if not pack_path.is_absolute():
        pack_path = (repo / pack_path).resolve()
    pack = json.loads(pack_path.read_text(encoding="utf-8"))

    token = read_token(args.token)
    if not token:
        raise SystemExit("missing token (set PRISMBOT_API_TOKEN or ~/.config/prismbot-core.env)")

    base_url = (args.base_url or pack.get("baseUrl") or "http://127.0.0.1:8799/api/omni").rstrip("/")
    categories = {c["id"]: dict(c, points=0.0, maxPoints=0.0, tests=0, passed=0) for c in pack.get("categories", [])}

    results = []
    total_points = 0.0
    total_max = 0.0

    for test in pack.get("tests", []):
        test_id = test["id"]
        t = test["type"]
        cat = test["category"]
        weight = float(test.get("weight", 1))
        method, path = ENDPOINT_MAP[t]
        payload = build_payload(test)
        url = base_url + path

        t0 = time.time()
        status, resp, err = request_json(method, url, token, payload, timeout=max(5, args.timeout))
        latency = int((time.time() - t0) * 1000)

        ok = bool(resp.get("ok")) if isinstance(resp, dict) else False
        text = flatten_text(resp)
        raw = json.dumps(resp, ensure_ascii=False)

        passed, reasons = check_expect(test.get("expect", {}), ok, text, raw, err, resp if isinstance(resp, dict) else {}, status)
        score = weight if passed else 0.0

        total_points += score
        total_max += weight

        if cat in categories:
            categories[cat]["tests"] += 1
            categories[cat]["passed"] += 1 if passed else 0
            categories[cat]["points"] += score
            categories[cat]["maxPoints"] += weight

        results.append({
            "id": test_id,
            "category": cat,
            "type": t,
            "weight": weight,
            "score": score,
            "passed": passed,
            "status": status,
            "ok": ok,
            "latencyMs": latency,
            "backend": resp.get("backend") if isinstance(resp, dict) else None,
            "model": resp.get("model") if isinstance(resp, dict) else None,
            "error": err,
            "reasons": reasons,
            "sample": text[:300],
        })

    cat_out = []
    for cid, c in categories.items():
        pct = round((c["points"] / c["maxPoints"] * 100.0), 2) if c["maxPoints"] else 0.0
        cat_out.append({
            "id": cid,
            "description": c.get("description", ""),
            "tests": c["tests"],
            "passed": c["passed"],
            "score": round(c["points"], 2),
            "maxScore": round(c["maxPoints"], 2),
            "percent": pct,
        })

    overall_pct = round((total_points / total_max * 100.0), 2) if total_max else 0.0

    report = {
        "ts": int(time.time() * 1000),
        "pack": str(pack_path),
        "baseUrl": base_url,
        "overall": {
            "score": round(total_points, 2),
            "maxScore": round(total_max, 2),
            "percent": overall_pct,
            "tests": len(results),
            "passed": sum(1 for r in results if r["passed"]),
            "failed": sum(1 for r in results if not r["passed"]),
        },
        "categories": cat_out,
        "results": results,
    }

    out_path = Path(args.out)
    if not out_path.is_absolute():
        out_path = (repo / out_path).resolve()
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(f"wrote {out_path}")
    print(f"overall: {report['overall']['score']}/{report['overall']['maxScore']} ({report['overall']['percent']}%)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
