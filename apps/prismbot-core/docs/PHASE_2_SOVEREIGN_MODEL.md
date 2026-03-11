# Phase 2 — Sovereign Model Pipeline (Omni)

Goal: evolve from a base local model profile to an Omni-tuned model lifecycle.

## Current status

- ✅ `omni-core:latest` is live in production routing.
- ✅ Local runtime serves `/api/omni/chat/completions` via `local-ollama`.
- 🚧 Phase 2 adds repeatable dataset/build/eval loop.

## New scripts

### 1) Build normalized training dataset

```bash
cd apps/prismbot-core
python3 scripts/omni_phase2_build_dataset.py --in training/phase2/raw.csv --out training/phase2/omni_sft.jsonl
```

Accepted input formats:
- CSV with `prompt,response` columns
- JSONL with either:
  - `{"prompt":"...","response":"..."}`
  - `{"messages":[{"role":"user","content":"..."},{"role":"assistant","content":"..."}]}`

### 2) Build Phase 2 Omni model profile

```bash
cd apps/prismbot-core
python3 scripts/omni_phase2_train_local.py \
  --dataset training/phase2/omni_sft.jsonl \
  --base-model llama3.2:3b \
  --target-model omni-core:phase2
```

This compiles a local model profile through Ollama (`ollama create`) and embeds curated behavior examples.

### 3) Evaluate endpoint quality

```bash
cd apps/prismbot-core
python3 scripts/omni_phase2_eval.py
```

Output report:
- `docs/evidence/phase2-eval.json`

## Promote Phase 2 model live

Set env:

```bash
OMNI_LOCAL_MODEL=omni-core:phase2
```

Then restart service:

```bash
systemctl --user restart prismbot-core.service
```

## Notes

- This is profile-tuning (behavior shaping), not full gradient fine-tuning.
- Next step after this phase: LoRA/SFT trainer integration for true weight updates.
