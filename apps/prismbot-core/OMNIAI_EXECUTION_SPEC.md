# OmniAI API — PrismBot Execution Spec (Prismtek)

## Mission
Build a one-stop PrismBot API that can cover chat, image, audio, search, tooling, and orchestration from one location.

## Constraints (hard)
- Design for current PrismBot repo/runtime.
- Mark each capability: **Real now / Stub / Planned**.
- Local-first execution preferred; external APIs are fallback only.
- No fake claims of full internalization unless local runtime exists and is running.

## P0 / P1 / P2

### P0 (ship now)
- Unified Omni surface in prismbot-core:
  - `GET /api/omni/health`
  - `GET /api/omni/capabilities`
  - `POST /api/omni/generate` (text + image)
- Route image generation to existing Studio local-first backend.
- Return explicit backend used (`local|openai`) and job metadata.

### P1 (7 days)
- Add audio endpoints (tts/stt local-first stubs + provider fallback).
- Add retrieval/search endpoint with local cache/index.
- Add orchestration endpoint for chained workflows.

**P1 early delivery (now):**
- `POST /api/omni/audio/speak` (local synth wav)
- `POST /api/omni/audio/transcribe` (local whisper-backed endpoint; pass-through fallback and no-speech note)
- `POST /api/omni/orchestrate/plan` (auto-generate graph `steps[]` from one prompt)
- `POST /api/omni/orchestrate/run` (plan + execute in one call; supports async mode)
- `GET /api/omni/orchestrate/jobs` + `GET /api/omni/orchestrate/jobs/{id}` (poll async run state)
- `GET /api/omni/orchestrate/stream/{id}` (SSE status stream)
- `POST /api/omni/orchestrate` (image + integration text workflow, plus graph-style `steps[]` execution)

### P2 (30 days)
- True local model stack upgrades (quality image + text).
- Fine-tune/adapters and policy engine.
- Tenant isolation + per-user OAuth model auth flow completion.

## Capability Matrix (current)
- Text chat/completion: **Real now** (`/api/ai/chat`, `/api/ai/complete`)
- Image generate/edit: **Real now** (Studio APIs, local-first)
- Unified omni endpoint: **Real now** (`/api/omni/*`, P0)
- Audio (tts/stt): **Planned**
- Video generation: **Planned**
- Embeddings/rerank/search RAG: **Stub/Planned**
- Fine-tuning/train jobs: **Planned**

## Notes
- Current local image backend is procedural and service-backed (`prismbot-local-image`).
- Next quality upgrade swaps backend internals without changing Omni API contract.
