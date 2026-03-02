# Mission Control Deploy Guide

This guide is for running `apps/mission-control` on your own server/domain/OpenClaw stack.

## 1) Prereqs

- Linux server with Docker + Docker Compose plugin
- Git
- Your own OpenClaw gateway URL/token (optional for now, but included in env for portability)
- Optional: reverse proxy (Nginx or Caddy) for HTTPS/custom domain

## 2) Clone + configure

```bash
git clone <YOUR_FORK_OR_REPO_URL>
cd <repo>/apps/mission-control
cp .env.example .env
```

Edit `.env`:

- `HOST=0.0.0.0`
- `PORT=8790` (or any unused port)
- `MISSION_CONTROL_TOKEN=<strong-random-token>` (recommended)
- `REQUIRE_TOKEN_FOR_READ=false` (default; set to `true` to require bearer auth on read endpoints too)
- `OPENCLAW_GATEWAY_URL=<your-own-gateway-url>`
- `OPENCLAW_GATEWAY_TOKEN=<your-own-gateway-token>`

> Keep `.env` local to your server. Never commit real tokens.

## 3) Start with Docker Compose

```bash
docker compose up -d --build
```

Check health:

```bash
curl -fsS http://127.0.0.1:${PORT:-8790}/health
```

View logs:

```bash
docker compose logs -f
```

Stop:

```bash
docker compose down
```

## 4) Update flow

From `apps/mission-control`:

```bash
git pull --ff-only
docker compose build --pull
docker compose up -d --remove-orphans
```

## 5) Family Admin UI (new)

Mission Control now includes a dedicated **Family Admin** flow with mobile/desktop readable sections:

- **Family Users** section (`/api/family/users`)
  - List users
  - Add user (scaffold)
  - Disable account (scaffold)
  - Reset password (placeholder response until auth backend is wired)
- **Family Sessions** section (`/api/family/sessions`)
  - Per-user private session counts
  - Total matched sessions
  - Last active timestamp
- **Direct Chat to Assistant** (`/api/family/chat`)
  - Safe local queue mode
  - Owner can submit assistant commands from Mission Control
  - Queue status is visible in dashboard
  - Current build intentionally does **not** auto-dispatch to a live assistant bridge without explicit backend wiring

Data files used by this flow:

- `data/family-users.json`
- `data/family-chat-queue.json`

## 6) API query controls (checkpoint #5)

The dashboard and API now support pagination/limits/sort controls for high-volume read endpoints:

- `GET /api/sessions?page=1&limit=25&sortBy=updatedAt&sortDir=desc&active=1`
- `GET /api/memory/files?page=1&limit=25&sortBy=updatedAt&sortDir=desc&q=daily`
- `GET /api/memory/read?file=memory/2026-02-26.md&lineStart=1&lineLimit=500&hitsLimit=30&q=project`
- `GET /api/search?q=Mission&limit=25` (and `type=memory` with paging/sort)

## 7) Optional helper scripts

```bash
./scripts/install.sh
./scripts/update.sh
```

## 8) Reverse proxy examples (custom domain + HTTPS)

### Nginx

```nginx
server {
  listen 80;
  server_name mission.example.com;

  location / {
    proxy_pass http://127.0.0.1:8790;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

Then add TLS via certbot or your preferred ACME automation.

### Caddy

```caddy
mission.example.com {
  reverse_proxy 127.0.0.1:8790
}
```

Caddy will automatically provision/renew HTTPS certificates by default.

## 9) Security notes (important)

- **Never commit `.env` with real secrets/tokens.**
- **Rotate tokens** if leaked, shared, or copied into logs/screenshots.
- **Local/VPN-first default:** keep app bound to private networks where possible and expose publicly only behind authenticated reverse proxy.
- Set `MISSION_CONTROL_TOKEN` in any non-local environment to protect write endpoints.
- Set `REQUIRE_TOKEN_FOR_READ=true` in public deployments if you want all `GET /api/*` endpoints token-protected.
- If strict read mode is enabled, send: `Authorization: Bearer <MISSION_CONTROL_TOKEN>` for dashboard/API reads.
- Use your **own** OpenClaw gateway URL/token (BYO config), never someone else's host credentials.
