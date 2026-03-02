# Rollback Plan (Phase D)

If unified runtime causes a blocker, use this rollback sequence.

## Trigger conditions
- Auth regression blocking logins
- Critical route unavailable (`/chat`, `/admin`, `/public`)
- Client (desktop/mobile) unable to reach core API

## Rollback steps

1. Stop core runtime
   - `pkill -f "apps/prismbot-core/src/server.js"` (or service stop)
2. Restart legacy runtimes (temporary)
   - `node apps/kid-chat-mvp/server.js`
   - `node apps/mission-control/server.js`
   - `node apps/public-chat/server.js`
3. Desktop fallback URL
   - set desktop entrypoint back to legacy app host if needed
4. Mobile fallback API base
   - set env to prior bridge/family endpoints
5. Open incident log entry in `memory/decisions/`

## Recovery back to core
- Fix root cause in `apps/prismbot-core`
- Re-run parity checklist
- Re-enable unified core runtime
