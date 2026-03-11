#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

python3 scripts/omni_phase3_build_dataset.py \
  --base training/phase2/omni_sft.jsonl \
  --feedback training/phase3/feedback.jsonl \
  --runs data/omni-runs.json \
  --out training/phase3/omni_sft_phase3.jsonl \
  --max-runs 80

python3 scripts/omni_phase2_train_local.py \
  --dataset training/phase3/omni_sft_phase3.jsonl \
  --base-model "${OMNI_PHASE3_BASE_MODEL:-omni-core:phase2}" \
  --target-model "${OMNI_PHASE3_TARGET_MODEL:-omni-core:phase3}" \
  --example-limit "${OMNI_PHASE3_EXAMPLE_LIMIT:-48}"

echo "phase3 build complete"
