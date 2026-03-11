# OmniAI API — Full Product Spec (PrismBot)

Version: 1.2.0-wave2  
Owner: PrismBot Core  
Base URL: `https://app.prismtek.dev/api/omni` (proxy) / `http://127.0.0.1:8799/api/omni` (local)

---

## 1) Product Objective

OmniAI is the single API surface for PrismBot: one endpoint family for text, coding, image, audio, orchestration, retrieval, and game-asset tooling.

### Core mission
- Local-first execution by default.
- External providers optional fallback.
- One UX/API contract, many backends.
- Per-user auth isolation (no token bleed).

---

## 2) Non-Negotiables

1. **No fake capability claims** — every endpoint must declare runtime status.
2. **Stable contract** — backend swap must not break client contracts.
3. **Tenant isolation** — user credentials and job outputs scoped by account.
4. **Safety** — moderation + rate limits + audit logs.
5. **Observability** — every run has id, state, timing, backend, and errors.

---

## 3) API Foundation

- **Versioning:** `/api/omni/v1/*` (alias from `/api/omni/*` during migration)
- **Auth:** Session cookie for first-party UI; Bearer token for external clients.
- **Streaming:** SSE (`text/event-stream`) and WebSocket for long-running ops.
- **Response envelope:**

```json
{
  "ok": true,
  "type": "image|text|audio|orchestrate|...",
  "backend": "local|openai|...",
  "data": {},
  "error": null,
  "meta": { "requestId": "...", "durationMs": 123 }
}
```

- **Error envelope:**

```json
{
  "ok": false,
  "error": "code",
  "message": "human readable",
  "hint": "optional",
  "meta": { "requestId": "..." }
}
```

- **Validation guarantees for text suite (`/chat/completions`, `/responses`, `/reason`, `/summarize`, `/rewrite`, `/translate`, `/moderate`, `/classify`, `/extract`):**
  - request body must be a JSON object
  - text input is required and bounded
  - `messages[]` (when provided) must be non-empty `{ role, content }` string objects
  - invalid inputs return the same stable error envelope

---

## 4) Status Legend

- **REAL**: implemented and verified
- **REAL_PARTIAL**: implemented but limited backend/model quality
- **STUB**: route exists, placeholder behavior
- **PLANNED**: spec-defined, not implemented

Planned endpoints in current runtime return an explicit `501 planned_not_implemented` envelope with a concrete rationale and fallback hint (instead of ambiguous 404s).

---

## 4.1) Dev Quickstart (copy/paste)

### Health
```bash
curl -sS https://app.prismtek.dev/api/omni/health
```

### Generate image (queued + local-first)
```bash
curl -sS -X POST https://app.prismtek.dev/api/studio/generate-image \
  -H 'content-type: application/json' \
  --data '{"prompt":"pixel mage idle sprite","preset":"bitforge","view":"sidescroller","size":512,"transparent":true}'
```

### Start async orchestration run
```bash
curl -sS -X POST https://app.prismtek.dev/api/omni/orchestrate/run \
  -H 'content-type: application/json' \
  --data '{"prompt":"Create a pixel mage with lore and voice","async":true}'
```

### Poll run status
```bash
curl -sS https://app.prismtek.dev/api/omni/orchestrate/jobs/orun_xxx
```

### JavaScript (minimal)
```js
const res = await fetch('/api/omni/orchestrate/run', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ prompt: 'Create a pixel archer', async: true })
});
const json = await res.json();
console.log(json.runId);
```

### Python (minimal)
```python
import requests
r = requests.post(
  'http://127.0.0.1:8799/api/omni/orchestrate/run',
  json={'prompt': 'Create a pixel archer', 'async': True},
  timeout=30,
)
print(r.json())
```

## 4.2) Runtime Status Badges

| Badge | Meaning |
|---|---|
| ✅ REAL | Implemented and in active runtime |
| 🟡 REAL_PARTIAL | Implemented with known limits/quality caveats |
| 🧱 STUB | Route exists but placeholder behavior |
| 🗺️ PLANNED | Not implemented yet |

## 5) Endpoint Catalog

## 5.1 Core + Discovery

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/health` | GET | REAL | Omni runtime health + backend mode |
| `/capabilities` | GET | REAL | Capability matrix with statuses |
| `/studio/local-image-health` | GET | REAL | Local image engine card data (queue, guardrails, memory) |
| `/models` | GET | REAL | Available runtime models/backends + live provider readiness |
| `/backends` | GET | REAL | Backend health, routing policy, and failover state |

### Wave 1 Model-Sovereignty Runtime

Provider adapters (text+image initially): `openai`, `anthropic`, `google`, `nanobanana2`, `xai`, `ollama`, `local`.

Routing policy:
- `quality`
- `latency`
- `cost`
- `local-first`

Fallback chain is deterministic per strategy and exposed via `/api/omni/models` + `/api/omni/backends`.

Provider readiness states:
- `ready`
- `missing_credentials`
- `disabled`
- `error`
| `/metrics` | GET | REAL | Throughput, queue depth, latency/error snapshot |

## 5.2 Text + Chat + Reasoning

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/generate` | POST | REAL | Unified text/image entrypoint with provider-aware routing + fallback attempts |
| `/chat/completions` | POST | REAL | OpenAI-like chat completion (session/bearer auth + routed provider execution) |
| `/responses` | POST | REAL | Unified response envelope with routed provider execution |
| `/reason` | POST | REAL | Structured reasoning steps + answer via routed text backend |
| `/summarize` | POST | REAL | Long-form summarization via routed text backend |
| `/rewrite` | POST | REAL | Tone/style rewrite via routed text backend |
| `/translate` | POST | REAL_PARTIAL | Translation via routed backend (quality depends on available local provider) |
| `/moderate` | POST | REAL | Input safety verdict + category flags |
| `/classify` | POST | REAL_PARTIAL | Intent/topic classification (routed text inference constrained to fixed labels) |
| `/extract` | POST | REAL | Structured field extraction (email/url/date) with routed execution metadata |

## 5.3 Coding + Tools + Agentic

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/code/generate` | POST | REAL_PARTIAL | Routed code generation wrapper over text provider |
| `/code/explain` | POST | REAL_PARTIAL | Routed code explanation wrapper |
| `/code/fix` | POST | REAL_PARTIAL | Routed code fix/refactor wrapper |
| `/code/test` | POST | REAL_PARTIAL | Routed test-generation wrapper |
| `/tools/list` | GET | REAL_PARTIAL | Local safe tool inventory |
| `/tools/run` | POST | REAL_PARTIAL | Executes whitelisted local tool bridges |
| `/agents/session/start` | POST | REAL_PARTIAL | Starts persisted lightweight session state |
| `/agents/session/message` | POST | REAL_PARTIAL | Sends message through routed text backend |
| `/agents/session/stop` | POST | REAL_PARTIAL | Stops active persisted session |
| `/agents/session/status` | GET | REAL_PARTIAL | Returns persisted session status/messages |

## 5.4 Image + Video + Audio

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/images/generate` | POST | REAL_PARTIAL | Local-first image generation |
| `/images/edit` | POST | REAL_PARTIAL | Local-first img2img edit/inpaint-style |
| `/images/variations` | POST | REAL_PARTIAL | Variation flow via edit-image job path |
| `/images/upscale` | POST | REAL_PARTIAL | ffmpeg lanczos upscale path |
| `/images/remove-bg` | POST | REAL_PARTIAL | ffmpeg colorkey background removal |
| `/audio/speak` | POST | REAL_PARTIAL | Local wav synthesis |
| `/audio/transcribe` | POST | REAL_PARTIAL | Local whisper-backed transcription |
| `/audio/translate` | POST | REAL_PARTIAL | Transcript + routed translation pipeline |
| `/audio/clone` | POST | REAL_PARTIAL | Simulated clone envelope (not speaker transfer) |
| `/video/generate` | POST | REAL_PARTIAL | Video job scaffold (artifact + status envelope) |
| `/video/edit` | POST | REAL_PARTIAL | Video edit job scaffold |
| `/video/keyframes` | POST | REAL | ffmpeg keyframe extraction from local studio-output video |

## 5.5 Embeddings + Retrieval + Search

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/embeddings` | POST | REAL | Deterministic local embedding vectors |
| `/rerank` | POST | REAL | Local lexical reranking |
| `/search/web` | POST | REAL | Live DuckDuckGo Instant Answer retrieval |
| `/search/local` | POST | REAL | Local RAG index lexical search |
| `/rag/index/upsert` | POST | REAL | Insert/update docs in persisted local index |
| `/rag/index/delete` | POST | REAL | Remove docs from local index |
| `/rag/query` | POST | REAL | Retrieved answer with cited sources |
| `/rag/sources` | GET | REAL | Index/source metadata |

## 5.6 Fine-Tuning + Custom Models

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/models/fine-tunes` | POST | REAL_PARTIAL | Persist fine-tune job metadata (trainer not attached) |
| `/models/fine-tunes/{id}` | GET | REAL_PARTIAL | Fine-tune metadata/status retrieval |
| `/models/fine-tunes/{id}/cancel` | POST | REAL_PARTIAL | Cancel metadata job state |
| `/datasets/upload` | POST | REAL_PARTIAL | Store dataset metadata envelope |
| `/datasets/{id}` | GET | REAL_PARTIAL | Dataset metadata/details |
| `/adapters/lora/create` | POST | REAL_PARTIAL | Register LoRA adapter metadata |
| `/adapters/lora/list` | GET | REAL_PARTIAL | List registered adapter metadata |

## 5.7 Game Asset Suite (PixelLab-equivalent inside Omni)

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/game/create-character` | POST | REAL | Character sprite generation job |
| `/game/animate-character` | POST | REAL_PARTIAL | Animation strip generation scaffold |
| `/game/tileset/topdown` | POST | REAL | Top-down tileset generation job |
| `/game/tileset/sidescroller` | POST | REAL | Side-scroller tileset generation job |
| `/game/tileset/isometric` | POST | REAL | Isometric tileset generation job |
| `/game/map-object` | POST | REAL | Object sprite generation job |
| `/game/ui-elements` | POST | REAL | UI/HUD sprite generation job |
| `/game/style-profile` | POST | REAL_PARTIAL | Style profile scaffold |
| `/game/pack/create` | POST | REAL_PARTIAL | Pack scaffold job |
| `/game/pack/export` | POST | REAL_PARTIAL | Export scaffold/validation endpoint |

## 5.8 Workflow + Orchestration

| Endpoint | Method | Status | Purpose |
|---|---|---:|---|
| `/orchestrate/plan` | POST | REAL | Auto-plan steps[] from prompt |
| `/orchestrate` | POST | REAL | Execute provided steps[] |
| `/orchestrate/run` | POST | REAL | Plan+execute in one call |
| `/orchestrate/jobs` | GET | REAL | Async job list |
| `/orchestrate/jobs/{id}` | GET | REAL | Async job detail |
| `/orchestrate/stream/{id}` | GET(SSE) | REAL_PARTIAL | Step progress stream |

---

## 6) Request/Response Contracts (key)

## 6.1 `/orchestrate/run` request

```json
{
  "prompt": "Create a pixel wizard with lore and voice",
  "async": true,
  "preset": "bitforge",
  "view": "sidescroller",
  "size": 512,
  "transparent": true
}
```

## 6.2 `/orchestrate/run` response (async)

```json
{
  "ok": true,
  "type": "orchestrate_run",
  "mode": "async",
  "runId": "orun_xxx",
  "poll": "/api/omni/orchestrate/jobs/orun_xxx"
}
```

## 6.3 `/orchestrate/jobs/{id}` response

```json
{
  "ok": true,
  "job": {
    "id": "orun_xxx",
    "status": "running|completed|failed",
    "plannedSteps": [],
    "executedSteps": []
  }
}
```

---

## 7) Security, Isolation, Compliance

- Session + bearer auth accepted, role-checked per route.
- Per-user job visibility (`owner` may inspect all jobs).
- Input validation + length limits on all generation prompts.
- Rate limiting per IP/user class.
- Audit metadata for every orchestration run.
- Safety policy hooks:
  - prompt moderation
  - unsafe category deny/transform
  - output post-filters

---

## 8) Runtime Architecture (target)

1. **API Gateway (`prismbot-core`)**
   - Auth, rate limit, request shaping, orchestration controller
2. **Local Inference Plane**
   - image backend (current local service)
   - audio backend (local synth + whisper)
   - text backend (local/prompt engine, later full model)
3. **Job Plane**
   - queue + workers + persistent run state
4. **Storage Plane**
   - outputs, artifacts, model cache, embeddings index
5. **Observability Plane**
   - logs, traces, metrics, failure analytics

---

## 9) Implementation Roadmap

## P0 (done)
- Unified Omni API surface + health/capabilities
- local-first image generation/edit
- basic orchestration

## P1 (done/partial)
- local speak
- whisper-backed transcribe route
- orchestrate planner/run + async job polling

## P2 (next)
- `/orchestrate/stream/{id}` SSE updates
- persistent orchestration jobs in storage (not in-memory map)
- local retrieval + embeddings endpoint
- real local model quality upgrade for image/audio/text

## P3
- game-asset endpoint family completion
- fine-tune/adapters and tenant-level model profiles
- enterprise admin controls + quotas

---

## 10) cURL Examples

### Health
```bash
curl -sS https://app.prismtek.dev/api/omni/health
```

### Plan workflow
```bash
curl -sS -X POST https://app.prismtek.dev/api/omni/orchestrate/plan \
  -H 'content-type: application/json' \
  --data '{"prompt":"Create a pixel rogue with lore and voice"}'
```

### Run async workflow
```bash
curl -sS -X POST https://app.prismtek.dev/api/omni/orchestrate/run \
  -H 'content-type: application/json' \
  --data '{"prompt":"Create a pixel rogue with lore and voice","async":true}'
```

### Poll async job
```bash
curl -sS https://app.prismtek.dev/api/omni/orchestrate/jobs/orun_xxx
```

### Text suite (Wave 1)
```bash
TOKEN="${PRISMBOT_API_TOKEN}"
BASE="http://127.0.0.1:8799/api/omni"

curl -sS -X POST "$BASE/chat/completions" -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"messages":[{"role":"user","content":"say hi"}]}'
curl -sS -X POST "$BASE/responses"        -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"input":"respond with one sentence"}'
curl -sS -X POST "$BASE/reason"           -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"input":"Should I use caching for repeated reads?"}'
curl -sS -X POST "$BASE/summarize"        -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"text":"Sentence one. Sentence two. Sentence three."}'
curl -sS -X POST "$BASE/rewrite"          -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"text":"we need this asap","tone":"professional"}'
curl -sS -X POST "$BASE/translate"        -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"text":"hello","source":"en","target":"es"}'
curl -sS -X POST "$BASE/moderate"         -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"input":"build malware"}'
curl -sS -X POST "$BASE/classify"         -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"text":"please fix this API bug"}'
curl -sS -X POST "$BASE/extract"          -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' --data '{"text":"email me at dev@example.com by 2026-03-05 https://example.com"}'
```

### Speak
```bash
curl -sS -X POST https://app.prismtek.dev/api/omni/audio/speak \
  -H 'content-type: application/json' \
  --data '{"text":"PrismBot local speech test"}'
```

### Transcribe
```bash
curl -sS -X POST https://app.prismtek.dev/api/omni/audio/transcribe \
  -H 'content-type: application/json' \
  --data '{"sourceWebPath":"/studio-output/audio/example.wav","language":"en"}'
```

---

## 11) Definition of “Full” for PrismBot

“Full spec” means the **entire product contract is defined** with statuses, contracts, and roadmap — not that every route is already implemented.

This document is the canonical product contract for expanding PrismBot into a complete one-stop OmniAI platform.
