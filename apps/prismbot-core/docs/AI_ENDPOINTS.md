# PrismBot Core AI / Omni Endpoints

## Primary Surface

Use Omni routes for provider-routed text/image/audio flows:
- `POST /api/omni/generate`
- `POST /api/omni/chat/completions`
- `POST /api/omni/responses`
- `GET /api/omni/health`
- `GET /api/omni/backends`
- `GET /api/omni/metrics`

Legacy compatibility routes remain available:
- `POST /api/ai/chat`
- `POST /api/ai/complete`

## Auth

Bearer token (or session cookie):
- `PRISMBOT_API_TOKEN=<token>`
- Header: `Authorization: Bearer <token>`

## Provider truth verification

Run credential-aware provider checks + readiness consistency audit:

```bash
cd apps/prismbot-core
npm run verify:providers:live
# Artifacts:
# - docs/omni-provider-verification-matrix.json
# - docs/omni-provider-verification-matrix.md
```

If a provider is uncredentialed, the matrix will stay truthful (`credentialed=false`, `pass_fail=skip`) and include exact enable/re-run steps.

## Quick live test

```bash
curl -sS http://127.0.0.1:8799/api/omni/chat/completions \
  -H 'content-type: application/json' \
  -H 'Authorization: Bearer <YOUR_TOKEN>' \
  -d '{"messages":[{"role":"user","content":"hello"}]}'
```

## Response notes

Omni text responses include routing evidence:
- `backend`
- `routing.strategy`
- `routing.fallbackChain`
- `routing.attempts[]` (skip/failure/success path)

`/api/omni/metrics` includes provider success/failure/timeout and latency percentiles.

## Pixel Superiority / Zoomquilt mode

High-quality pixel mode now supports strict failure semantics (no mush fallback):
- `POST /api/omni/images/zoomquilt`
- `POST /api/omni/game/zoomquilt`
- `POST /api/omni/orchestrate` or `/api/omni/orchestrate/run` with `steps[].type="zoomquilt"`

Key params:
- `pixelMode: "hq" | "standard"`
- `strictHq: true|false` (when true + HQ requested, request fails explicitly if backend cannot satisfy HQ)
- `paletteLock`, `paletteSize`
- `nearestNeighbor`
- `antiMush`
- `coherenceChecks`, `coherenceThreshold`
- `layers`, `anchorMotif`

Smoke test:
```bash
cd apps/prismbot-core
npm run smoke:omni:pixel
```
