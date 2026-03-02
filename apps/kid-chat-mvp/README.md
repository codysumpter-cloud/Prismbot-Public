# Prism Family Chat (Unified Family + Admin App)

Runs on one app/port (`8787`) with:
- Family login/chat experience (simple and readable)
- Per-user private history/session scope
- Self-managed profile context for each family user
- Admin-only dashboard at `/admin` (also same SPA if admin logs in)

## Start

```bash
cd apps/kid-chat-mvp
cp .env.example .env
# set ADMIN_PASSWORD before first run
node server.js
```

Open:
- Local/VPN: `http://<host>:8787/`
- Admin route: `http://<host>:8787/admin`

## Auth + Roles

- `admin` role: owner only, full dashboard + user/password controls + **operator mode** in direct admin chat (allowlisted actions only)
- `family_user` role: chat + own profile only + **personal customization mode** (self-only builder/profile changes)

Bootstrap admin defaults from `.env`:
- username: `ADMIN_USERNAME` (default `admin`)
- password: `ADMIN_PASSWORD` (default `change-me-now`)

**Reset flow:** admin dashboard → User Management → “Reset password” on a family user. The API returns a temp password; user signs in and continues.

## Data Files

- `users.json` (new): accounts + roles + password hashes
- `profiles.json`: now keyed by user id (per-user profile context)
- `history.json`: now nested by user id and session id (private history)
- `tasks.json` (new): admin queue/status items
- `activity.json` (new): recent activity events

## Migration Notes

On first boot of new server:
1. `users.json` is created if missing, with admin + migrated family users from legacy profile IDs.
2. Legacy `history.json` (`{profileId: [messages]}`) is migrated into `{userId: {default: [messages]}}`.
3. Legacy list-style `profiles.json` is converted to per-user map and preserved where possible by creating matching user accounts.

## Security Model (local/VPN assumptions)

- Server-side auth required for all API endpoints (except login/me static)
- Server-side role checks for `/api/admin/*`
- Passwords are stored as `scrypt` hashes
- Session token is an `HttpOnly` cookie (`fc_session`)
- No secret/env values are exposed to client

## Builder Mode + Onboarding (Checkpoint 1)

Family users now get a first-login onboarding wizard (4 steps) covering:
1. What they can do in their own space
2. What stays admin-only
3. How to use Build Your Experience
4. Privacy scope (per-user storage/isolation)

### Family UI additions
- **Capability badges**: “What you can do” vs “Requires admin”
- **Build Your Experience**:
  - layout preset (`focus`, `balanced`, `explore`)
  - widget toggles (safe allowlist only)
  - help center pin toggle
- **Help Center** panel with prompt examples + quick-start recipes

### Backend enforcement
- Per-user builder state is stored in `builder.json`
- Builder API is `family_user` only (`/api/builder/me`)
- Payload is strictly sanitized to an allowlisted schema
- No builder input can affect admin/server/tooling controls
- Admin/owner powers are unchanged and still protected by `/api/admin/*` role checks

## Automated Smoke Test

```bash
cd apps/kid-chat-mvp
node smoke.test.js
```

Checks:
- admin operator mode executes allowlisted actions (create user/task)
- family user can update only own personalization via chat
- blocked cross-user/admin attempts are denied
- non-admin access to admin endpoints is rejected

## Smoke Test Checklist

1. Login as admin (`/`), verify admin dashboard visible.
2. Create family user, capture temp password.
3. Log out, log in as family user, verify:
   - onboarding wizard appears on first complete login
   - chat works
   - history only shows own messages
   - can edit own profile
   - can save/load builder config
   - cannot call admin endpoints
4. Create a second family user and verify builder config isolation between users.
5. Log in as admin, verify:
   - users list
   - sessions overview
   - task queue create/update
   - activity feed
   - live admin chat shows running/done status

## Rollout / Rollback

### Rollout
- Back up existing `profiles.json` and `history.json`
- Deploy updated files
- Set `.env` admin credentials
- Start server and verify smoke tests

### Fallback / rollback
- Stop server
- Restore previous `server.js`, `web/`, and backed-up `profiles.json` + `history.json`
- Remove newly introduced files (`users.json`, `tasks.json`, `activity.json`) if reverting fully
- Restart old version
