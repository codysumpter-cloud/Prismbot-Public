# PrismBot Mobile (MVP)

iPhone-first app shell that can run today while your Google VM stays live.

## What this gives you now

- Native mobile chat UI (Expo/React Native)
- Local chat cache (AsyncStorage)
- Runtime mode switcher: **Local / Hybrid / Remote**
- Bridge health indicator + queued outbox count
- Device-saved session profile (display name + session key)
- In-app family login with role-aware status (admin vs family user)
- Safe fallback mode if backend endpoint is down
- Endpoint-based transport so you can keep Google VM active until you choose to cut over

## Quick start

```bash
cd apps/prismbot-mobile
./scripts/select-env.sh dev   # presets in env-presets/{dev|beta|prod}.env.sample
npm install
npm run start
```

Then:

- Press `i` in Expo terminal (iOS simulator), or
- Scan QR with Expo Go on iPhone

## Environment

Set `.env` values:

- `EXPO_PUBLIC_PRISMBOT_API_BASE` → your unified PrismBot Core endpoint
- `EXPO_PUBLIC_PRISMBOT_FAMILY_API_BASE` → same PrismBot Core endpoint (family login is unified)
- `EXPO_PUBLIC_PRISMBOT_SESSION_KEY` → stable mobile session key

Example:

```env
EXPO_PUBLIC_PRISMBOT_API_BASE=https://your-prismbot-core.example.com
EXPO_PUBLIC_PRISMBOT_SESSION_KEY=agent:main:mobile:prismtek
```

## On-device brain (inside the app)

The app now includes a local brain with device-stored memory:

- Runs directly on the phone while app is open
- Supports `remember that ...` and `what do you remember`
- Stores local memory in AsyncStorage on that device

Use **Local** mode for fully on-device chat.
Use **Hybrid** mode to prefer server replies and auto-fallback to local.

## OpenClaw bridge (live PrismBot brain)

This bridge lets mobile messages hit your live OpenClaw session safely.

```bash
cd ~/.openclaw/workspace/apps/prismbot-mobile
cp bridge.env.example .bridge.env
# edit BRIDGE_TOKEN before start
set -a; source .bridge.env; set +a
node openclaw-bridge-server.js
```

Then set mobile `.env`:

```env
EXPO_PUBLIC_PRISMBOT_API_BASE=https://YOUR-PRISMBOT-CORE-ENDPOINT
EXPO_PUBLIC_PRISMBOT_BRIDGE_TOKEN=optional-if-you-add-proxy-auth
EXPO_PUBLIC_PRISMBOT_SESSION_KEY=agent:main:mobile:prismtek
```

Health check:

```bash
curl http://127.0.0.1:8799/api/health
```

## Run bridge as persistent service (systemd user)

```bash
cd ~/.openclaw/workspace/apps/prismbot-mobile
cp bridge.env.example .bridge.env
# edit BRIDGE_TOKEN
./deploy/install-user-bridge-service.sh
```

Service controls:

```bash
systemctl --user status prismbot-bridge.service
systemctl --user restart prismbot-bridge.service
journalctl --user -u prismbot-bridge.service -n 100 --no-pager
```

## Add nginx + TLS for iPhone access

```bash
cd ~/.openclaw/workspace/apps/prismbot-mobile
./deploy/install-nginx-tls.sh your.domain.com
```

This keeps your Google VM backend alive while mobile connects securely over HTTPS.

### Optional dev-only echo bridge

```bash
node dev-bridge-server.js
```

## iPhone install without public App Store

- **Fast path:** Xcode direct install
  - Free Apple ID: re-sign every ~7 days
  - Paid dev account: recommended
- **Private distribution:** TestFlight internal testing

## Safe rollout sequence

1. Keep Google VM unchanged
2. Install app and connect to live endpoint
3. Verify conversation flow + reconnect behavior
4. Only then decide whether to migrate backend host

This is intentionally parallel-safe so there is no forced downtime.
