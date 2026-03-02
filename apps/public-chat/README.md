# Public Chat MVP

Simple public-facing chat app using Node `http` style (no framework), with:

- polished single-page UI
- anonymous per-browser session id (stored in `localStorage`)
- per-IP rate limiting
- input moderation guardrails
- BYO OpenAI API key entered in UI and stored client-side only

## Run locally

```bash
cd apps/public-chat
cp .env.example .env
node server.js
```

Default URL: `http://localhost:8788`

## Config (`.env`)

- `PORT` (default `8788`)
- `HOST` (default `0.0.0.0`)
- `MAX_INPUT` (default `800` chars)
- `RATE_LIMIT_MAX` (default `20` requests)
- `RATE_LIMIT_WINDOW_MS` (default `60000` ms)
- `OPENAI_MODEL` (default `gpt-4o-mini`)
- `ALLOWED_ORIGINS` (comma-separated exact origins, e.g. `https://chat.example.com`)
- `TRUST_PROXY` (`true` to trust `x-forwarded-for` when behind proxy)
- `AUDIT_LOG` (default `./logs/audit.log`)
- `BLOCK_DURATION_MS` (temporary IP block duration after repeated unsafe prompts)
- `MODERATION_STRIKES_TO_BLOCK` (unsafe prompt count before temporary block)

## Notes

- API key is **not** saved server-side. It is sent with each request from the browser.
- Server keeps short in-memory session history by anonymous session id (for context only).
- Moderation is guardrail-based with denylist patterns + safe refusal messaging.
- Hardening included now: strict security headers, optional origin allowlist, request IDs, audit logging, and temporary auto-blocks after repeated unsafe prompts.
- This MVP is still for development/early staging. For wide internet exposure, add full production hardening (see below).

## Production hardening checklist (before public internet)

- Put behind reverse proxy (Nginx/Caddy) with TLS
- Add strict CORS/origin allowlist
- Add CAPTCHA and abuse/bot protections
- Add stronger moderation (OpenAI moderation endpoint + audit logs)
- Add persistent, distributed rate limiting (Redis) for multi-instance deployments
- Add auth/admin controls and observability
