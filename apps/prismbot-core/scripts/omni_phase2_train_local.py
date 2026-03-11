#!/usr/bin/env python3
"""
Phase 2 local sovereign-model builder for Omni.

This script does not do gradient training; it builds an Omni-owned local model
profile from a base model + curated examples, then compiles it with Ollama.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
from pathlib import Path
from typing import List, Tuple


def _clean(text: str, max_len: int = 600) -> str:
    text = re.sub(r"\s+", " ", str(text or "").strip())
    return text[:max_len]


def parse_examples(dataset_path: Path, limit: int) -> List[Tuple[str, str]]:
    examples: List[Tuple[str, str]] = []
    if not dataset_path.exists():
        return examples

    for line in dataset_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            row = json.loads(line)
        except Exception:
            continue

        user = ""
        assistant = ""

        if isinstance(row.get("messages"), list):
            for m in row["messages"]:
                role = str(m.get("role", "")).lower()
                content = str(m.get("content", ""))
                if role == "user":
                    user = content
                elif role == "assistant":
                    assistant = content
        else:
            user = str(row.get("prompt") or row.get("input") or "")
            assistant = str(row.get("response") or row.get("output") or "")

        user = _clean(user)
        assistant = _clean(assistant)
        if user and assistant:
            examples.append((user, assistant))
        if len(examples) >= limit:
            break

    return examples


def build_modelfile(base: str, examples: List[Tuple[str, str]]) -> str:
    lines = [
        f"FROM {base}",
        "",
        "PARAMETER temperature 0.18",
        "PARAMETER top_p 0.9",
        "PARAMETER num_ctx 8192",
        "PARAMETER repeat_penalty 1.1",
        "",
        'SYSTEM """',
        "You are Omni, Prismtek's sovereign local model.",
        "Operate with production-grade accuracy, but sound human and natural.",
        "Rules:",
        "- Do not parrot user input unless asked.",
        "- Prefer actionable outputs over vague advice.",
        "- If context is missing, ask one short clarifying question.",
        "- Keep responses concise, warm, and conversational (not robotic).",
        "- Show personality when appropriate: confidence, humor, and friendly tone.",
        "- Never claim actions you did not perform.",
        "- For arithmetic requests, compute exactly and return only the requested numeric format.",
        "- For code-fix requests, return corrected code directly unless explanation is explicitly requested.",
    ]

    if examples:
        lines.append("")
        lines.append("Behavior examples:")
        for i, (u, a) in enumerate(examples, 1):
            lines.append(f"[{i}] User: {u}")
            lines.append(f"[{i}] Assistant: {a}")

    lines.extend(['"""', ""])
    return "\n".join(lines)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dataset", default="training/phase2/omni_sft.jsonl", help="JSONL dataset path")
    ap.add_argument("--base-model", default=os.getenv("OMNI_PHASE2_BASE_MODEL", "llama3.2:3b"))
    ap.add_argument("--target-model", default=os.getenv("OMNI_PHASE2_TARGET_MODEL", "omni-core:phase2"))
    ap.add_argument("--example-limit", type=int, default=24)
    ap.add_argument("--output", default="models/omni-core/Modelfile.phase2")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(__file__).resolve().parents[1]
    dataset = (root / args.dataset).resolve()
    output = (root / args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)

    examples = parse_examples(dataset, max(1, args.example_limit))
    modelfile = build_modelfile(args.base_model, examples)
    output.write_text(modelfile, encoding="utf-8")

    print(f"wrote modelfile: {output}")
    print(f"examples embedded: {len(examples)}")

    if args.dry_run:
        print("dry-run only (skipping ollama create)")
        return 0

    cmd = ["ollama", "create", args.target_model, "-f", str(output)]
    print("running:", " ".join(cmd))
    proc = subprocess.run(cmd)
    if proc.returncode != 0:
        return proc.returncode

    print(f"ok: built {args.target_model}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
