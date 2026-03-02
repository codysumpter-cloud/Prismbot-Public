# PrismBot Desktop (Local-contained shell)

Goal: desktop app that launches PrismBot stack in a contained window.

## Current v0.4 (Phase C)
- Electron shell app
- Starts OpenClaw gateway in **background service mode** (no foreground cmd gateway window)
- Starts unified `prismbot-core` runtime (localhost only)
- Opens PrismBot at `http://127.0.0.1:8799/app/chat`
- On app close, managed local services are terminated

## Run
```bash
cd apps/prismbot-desktop
npm install
npm run start
```

## Notes
- Local stack is bound to `127.0.0.1` so it stays on your machine/home network context.
- Gateway is started via `openclaw gateway start` service mode (windowless) instead of foreground `openclaw gateway`.
- When app closes, local managed processes shut down so idle network usage drops.
- This keeps your existing Google VM flow untouched.

## Next steps for full contained OpenClaw
1. Add health checks + process supervision
2. Spawn bridge service in-app
3. Add app settings for local-only vs hybrid
4. Package desktop binaries (Windows/Linux)
