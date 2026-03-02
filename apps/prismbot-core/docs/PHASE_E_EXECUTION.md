# Phase E Execution (Production Cutover)

## Objective
Finalize PrismBot Core as the single production runtime and complete post-merge hardening.

## Completed

- Core confirmed as default backend target for desktop and mobile clients.
- Added auth/login rate limiting in core API.
- Hardened session cookie defaults with `HttpOnly + SameSite=Lax` and optional `Secure` mode via env.
- Added production smoke test script for routes and APIs.
- Added final release checklist for VM/old-laptop cutover.

## Environment knobs

- `CORE_SESSION_COOKIE` (default: `pb_core_session`)
- `CORE_COOKIE_SECURE=true|false` (set true behind HTTPS)

## Production guidance

- Use one Core runtime per node.
- Put Core behind HTTPS reverse proxy.
- Enable `CORE_COOKIE_SECURE=true` in production.
- Run smoke checks after every deployment.
