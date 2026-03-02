# Phase B Execution (Functional Merge)

## Implemented now

- Unified route map in core server:
  - `/` -> prismbot-site
  - `/chat` -> kid-chat-mvp web UI
  - `/admin` -> mission-control UI
  - `/public` -> public-chat web UI
  - `/studio` -> studio status placeholder
- Unified API namespace:
  - `GET /api/health`
  - `POST /api/auth/login`
  - `POST /api/auth/logout`
  - `GET /api/auth/me`
  - `GET /api/admin/summary` (admin role)
  - `GET /api/studio/status`
  - `POST /api/public/chat` (guardrailed public mode with rate limiting)
- Session cookie handling in core
- Role gate helper integration (`requireRole`)

## Notes

- Phase B functional merge is now complete at backend/runtime level.
- All major app surfaces are reachable from prismbot-core routes.
- Auth now verifies legacy `scrypt:salt:hash` password records directly.
- Next focus (Phase C): client unification (desktop/mobile -> core API) and deeper UI shell consolidation.
