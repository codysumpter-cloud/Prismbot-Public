# Final Release Checklist (All Phases Complete)

## Runtime
- [x] `prismbot-core` is the unified backend
- [x] Legacy app folders marked archived
- [x] Compatibility wrappers present

## Routes
- [x] `/`
- [x] `/chat`
- [x] `/admin`
- [x] `/public`
- [x] `/studio`
- [x] `/app/*` unified shell

## API
- [x] Auth endpoints active
- [x] Unified chat endpoint active
- [x] Public guardrails + rate limiting active
- [x] Admin summary + studio status active

## Security
- [x] Password hash verification (`scrypt`) enabled
- [x] Login rate limiting enabled
- [x] Session cookie hardened (secure toggle ready)

## Clients
- [x] Desktop points to core (`/app/chat`)
- [x] Mobile points to core (`/api/chat`, `/api/health`)

## Operations
- [x] Health and recovery scripts present
- [x] Rollback plan documented
- [x] Phase E smoke test script added

## Final manual checks (recommended)
- [ ] Verify mobile login on physical device
- [ ] Verify desktop packaged build (non-dev)
- [ ] Verify production HTTPS with secure cookies enabled
