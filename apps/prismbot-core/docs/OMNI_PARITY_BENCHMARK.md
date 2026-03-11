# Omni Parity Benchmark (v1)

This benchmark tracks how close Omni is to practical replacement parity for major paid AI stacks.

## Scope Targets

- ChatGPT-equivalent: chat/reasoning/rewrite/summarize
- Grok-equivalent: coding + agent/tool workflows
- Ollama-equivalent: local model-backed completion stability
- Nano Banana / PixelLab.ai-equivalent: image/audio/video + game-asset APIs

## Benchmark Pack

- Pack files:
  - `benchmarks/omni-parity-pack.v1.json`
  - `benchmarks/omni-parity-pack.v1.1.json` (stricter structured assertions)
- Runner: `scripts/omni_parity_eval.py`
- Latest reports:
  - `docs/evidence/omni-parity-report.json` (v1 baseline)
  - `docs/evidence/omni-parity-report.v1.1.json` (current)

## Weighted Categories (100 total)

- `chat_core` = 25
- `reasoning_math` = 15
- `coding` = 20
- `retrieval_tools` = 10
- `media_api` = 20
- `orchestration` = 10

## Scoring Rule

- Each test has a fixed `weight`.
- Pass = full points; fail = 0 points.
- Overall parity score = `sum(points) / sum(maxPoints) * 100`.

## How to Run

```bash
cd apps/prismbot-core
./scripts/omni_parity_eval.py
```

Optional overrides:

```bash
./scripts/omni_parity_eval.py \
  --base-url http://127.0.0.1:8799/api/omni \
  --pack benchmarks/omni-parity-pack.v1.json \
  --out docs/evidence/omni-parity-report.json
```

Runner token resolution order:

1. `--token`
2. `PRISMBOT_API_TOKEN` env var
3. `~/.config/prismbot-core.env`

## Latest Run (auto-generated baseline)

- Timestamp: from `docs/evidence/omni-parity-report.json`
- Overall: **76.0 / 100.0**
- Passed tests: **15 / 21**

Category breakdown:

- `chat_core`: 76%
- `reasoning_math`: 100%
- `coding`: 75%
- `retrieval_tools`: 40%
- `media_api`: 100% (contract-level checks)
- `orchestration`: 30%

## Notes

This first benchmark pass is intentionally contract-heavy and deterministic.
It is not the final quality eval for creative outputs (image/video aesthetics, voice cloning realism, long-horizon agent autonomy).

Next benchmark iteration should add:

- Golden-output semantic scoring for code correctness
- Human/LLM-judge quality rubric for image/video/audio quality
- End-to-end orchestration outcome checks (not just `ok` envelopes)
- Reliability runs under load (repeatability + latency percentiles)
