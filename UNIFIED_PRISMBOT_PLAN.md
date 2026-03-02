# Unified PrismBot Plan ("Big Bad Mamma Jamma")

## Objective
Consolidate **all app folders** into one product surface with one runtime, one auth system, one data model, and one deployment path.

## Current apps to absorb
- `apps/kid-chat-mvp`
- `apps/mission-control`
- `apps/prismbot-desktop`
- `apps/prismbot-mobile`
- `apps/public-chat`
- `apps/prismbot-site`
- `apps/pixel-pipeline`

## Keep / Merge / Retire Strategy

### Keep as core base
- `apps/mission-control` (server/control-plane baseline)

### Merge into core
- `kid-chat-mvp` -> family chat + role-aware auth + private history/profile
- `prismbot-site` -> public landing + waitlist + analytics route bundle
- `public-chat` -> public-facing chat mode under guarded route
- `pixel-pipeline` -> studio module + packaging workflow hooks

### Keep as clients (not separate backends)
- `prismbot-mobile` -> unified API client
- `prismbot-desktop` -> unified API + local service launcher client

## Unified Route Map (target)
- `/` marketing + waitlist
- `/chat` authenticated chat workspace (family/admin/public modes)
- `/admin` operator dashboard
- `/studio` image/animation/pipeline operations
- `/api/*` single API namespace

## Unified Data Model (target)
- users
- sessions
- profiles
- history
- tasks
- activity
- builder
- integrations

## Phases

### Phase A - Foundation
1. [x] Freeze new standalone app feature work
2. [x] Create `apps/prismbot-core/` as unified server workspace
3. [x] Define canonical schema + migration scripts

### Phase B - Functional merge
4. [x] Port auth/role/history flows from `kid-chat-mvp` (hashed-password auth + unified `/api/chat` broker)
5. [x] Port mission-control admin APIs/UI panels (`/admin` route + admin summary API in core)
6. [x] Port public landing/waitlist from `prismbot-site`
7. [x] Port public-chat as optional mode under one guardrail system (`/public` + `/api/public/chat`)
8. [x] Port pixel pipeline as studio module (`/studio` + `/api/studio/status` integration hook)

### Phase C - Client unification
9. [x] Point mobile to unified API
10. [x] Point desktop shell to unified API/service controls

### Phase D - Decommission
11. [x] Parity checklist + smoke tests (core route/API smoke complete; device/package verification tracked in checklist)
12. [x] Mark old app folders as archived
13. [x] Keep thin compatibility wrappers only if needed

### Phase E - Production cutover
14. [x] Harden auth/session controls (rate limits + secure-cookie toggle)
15. [x] Add production smoke script + final release checklist
16. [x] Declare PrismBot Core as default production runtime for new deployments

## Success Criteria
- One login system
- One runtime + one deployment
- One source of truth for user/session data
- Existing major features preserved
- No duplicate tunnel/app URLs required for normal use
