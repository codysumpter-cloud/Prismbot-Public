# PrismBot Core Parity Checklist

## Routes / UX
- [x] `/` public site available
- [x] `/chat` family chat UI available
- [x] `/admin` mission-control UI available
- [x] `/public` public chat UI available
- [x] `/studio` studio hook available
- [x] `/app/*` unified shell navigation available

## API
- [x] `/api/health`
- [x] `/api/auth/login`
- [x] `/api/auth/logout`
- [x] `/api/auth/me`
- [x] `/api/chat`
- [x] `/api/public/chat`
- [x] `/api/admin/summary`
- [x] `/api/studio/status`

## Clients
- [x] Desktop points to PrismBot Core runtime
- [x] Mobile points to PrismBot Core API
- [ ] Mobile end-to-end login verified on device
- [ ] Desktop packaged build verified (non-dev)

## Data & Migration
- [x] Core schema v1 exists
- [x] bootstrap migration exists
- [x] kid-chat import migration exists
- [x] mission-control import migration exists
- [ ] migration dry-run report committed

## Decommission readiness
- [x] legacy app wrappers added (`scripts/start-prismbot-legacy-compat.*`)
- [x] legacy folders marked archived
- [x] rollback steps documented (`docs/ROLLBACK_PLAN.md`)
