# PrismBot Core (Unified App)

This folder is the consolidation target for all PrismBot app features.

## Purpose
Single backend/runtime for:
- public site + waitlist
- chat workspace (family/admin/public)
- operator/admin dashboard
- studio/pixel pipeline hooks
- unified API for desktop + mobile
- OmniAPI (`/api/omni/*`) for text, image, audio, and orchestration

## OmniAPI (Current)
Runtime truth is maintained in:
- `OMNIAI_FULL_SPEC.md` (status: REAL / REAL_PARTIAL / PLANNED)
- `OMNIAI_OPENAPI_FULL.yaml` (contract)
- `scripts/omni-live-matrix.sh` (endpoint live verification runner)
- `scripts/omni-provider-live-verify.js` (credential-aware provider live verification matrix)
- `scripts/omni-provider-conformance.js` (execution conformance checks)

Current runtime includes:
- Core/discovery (`/health`, `/capabilities`, `/metrics`, `/backends`, `/models`, docs routes)
- Text suite (`/generate`, `/chat/completions`, `/responses`, `/reason`, `/summarize`, `/rewrite`, `/translate`, `/moderate`, `/classify`, `/extract`)
- Image/audio/video (`/images/generate`, `/images/edit`, `/audio/speak`, `/audio/transcribe`, `/video/generate`, `/video/edit`, `/video/keyframes`)
- Retrieval (`/embeddings`, `/rerank`, `/search/web`, `/search/local`, `/rag/*`)
- Game asset suite (`/game/*`)
- Orchestration (`/orchestrate`, `/orchestrate/plan`, `/orchestrate/run`, `/orchestrate/jobs*`, `/orchestrate/stream/{id}`)
- Explicit planned stubs (501 + `planned_not_implemented`) for code/tools/agents/training families.

All `/api/omni/*` routes are auth-gated by session cookie or bearer token (`PRISMBOT_API_TOKEN`).

## Studio + Local Image Engine
- `POST /api/studio/generate-image`
- `POST /api/studio/edit-image`
- `GET /api/studio/local-image-health`
- queued execution + retry policy + persistent job state
- local-image guardrails (profile, memory limits, queue controls)
- end-user-safe asset URL shaping (`publicUrl`) for image/audio/video outputs while preserving `outputWebPath` for internal/UI compatibility

### Public Asset URL Configuration (Production)
Set these env vars in production when serving assets from a public domain:
- `CORE_PUBLIC_ASSET_BASE_URL` (e.g. `https://assets.prismtek.dev` or `https://app.prismtek.dev`)
- `CORE_PUBLIC_ASSET_MODE=public|private`
- `CORE_PUBLIC_ASSET_SIGNING_SECRET` (optional placeholder signing hook)

Behavior:
- `outputWebPath` and `webPath` remain for backward compatibility/internal use.
- `publicUrl` (and `outputWebPathPublicUrl` where relevant) is added for clean end-user delivery.
- Internal absolute filesystem paths are not returned in user-facing payloads.

## Spec + Contract Files
- `OMNIAI_FULL_SPEC.md`
- `OMNIAI_OPENAPI_FULL.yaml`
- `OMNIAI_OPENAPI_P0.yaml`
- `OMNIAI_EXECUTION_SPEC.md`

## Minimal SDK helpers
- `sdk/omni-client.js`
- `sdk/omni_client.py`

## Omni Runtime Config (Wave 1 model-sovereignty)
Strategy + provider toggles:
- `OMNI_ROUTING_STRATEGY=quality|latency|cost|local-first`
- `OMNI_OPENAI_ENABLED=true|false`
- `OMNI_ANTHROPIC_ENABLED=true|false`
- `OMNI_GOOGLE_ENABLED=true|false`
- `OMNI_XAI_ENABLED=true|false`
- `OMNI_NANOBANANA2_ENABLED=true|false`
- `OMNI_OLLAMA_ENABLED=true|false`
- `OMNI_LOCAL_ENABLED=true|false`
- `OMNI_TEXT_MODEL_DEFAULT=<model>` (balanced profile default)
- `OMNI_TEXT_MODEL_FAST=<model>` (fast profile)
- `OMNI_TEXT_MODEL_QUALITY=<model>` (quality profile)

Execution hardening knobs:
- `OMNI_PROVIDER_TIMEOUT_MS` (default `12000`)
- `OMNI_PROVIDER_MAX_ATTEMPTS` (default `2`)
- `OMNI_PROVIDER_RETRY_DELAY_MS` (default `200`)
- `OMNI_PROVIDER_BREAKER_FAILURES` (default `3`)
- `OMNI_PROVIDER_BREAKER_COOLDOWN_MS` (default `30000`)
- `CORE_OMNI_RECOVERY_MAX_ATTEMPTS` (default `1`, restart recovery retries before dead-letter)

Credential env keys (readiness becomes `missing_credentials` if absent):
- OpenAI: `OPENAI_API_KEY`
- Anthropic: `ANTHROPIC_API_KEY`
- Google: `GOOGLE_API_KEY` or `GEMINI_API_KEY`
- xAI: `XAI_API_KEY`
- Nano Banana 2: `OMNI_NANOBANANA2_CHAT_URL` (+ optional `OMNI_NANOBANANA2_API_KEY`)
- Ollama: `OMNI_OLLAMA_HOST` (default `http://127.0.0.1:11434`)
- Local: built-in (always credential-ready when enabled)

Readiness states surfaced from `/api/omni/backends` and `/api/omni/models`:
- `ready`
- `missing_credentials`
- `disabled`
- `error`

## Phase 2 Sovereign Model Loop
- Dataset normalization: `npm run phase2:dataset`
- Local model build: `npm run phase2:train`
- Endpoint eval report: `npm run phase2:eval`
- Parity gate (fail below threshold): `OMNI_PHASE2_GATE_MIN_PERCENT=90 ./scripts/omni_phase2_gate.sh`
- Guide: `docs/PHASE_2_SOVEREIGN_MODEL.md`

## Phase 3 Personality + Feedback Loop
- Build phase3 dataset (base + feedback + run outputs): `npm run phase3:dataset`
- Build local phase3 model: `npm run phase3:train`
- Promote phase3 as default/quality model: `npm run phase3:promote`
- Optional gate before/after promote: `OMNI_PHASE2_GATE_MIN_PERCENT=90 ./scripts/omni_phase2_gate.sh`

## Next implementation steps
1. Expand capability coverage (video, embeddings, retrieval)
2. Improve NLP endpoint quality from heuristic to model-backed pipelines
3. Add dedicated external API key issuance/rotation for bearer clients
4. Add integration tests for all `/api/omni/*` contracts
