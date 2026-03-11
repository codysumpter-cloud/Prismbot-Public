# PrismBot Workspace

This repository contains the PrismBot workspace, operating docs, app prototypes, and execution pipelines.

## Core Workspace Files

- `AGENTS.md` — workspace operating rules
- `SOUL.md` / `USER.md` / `IDENTITY.md` — identity + user context
- `HEARTBEAT.md` — periodic check instructions
- `memory/` — session memory + curation system
- `scripts/` — utility and recovery scripts

## Apps

- `apps/kid-chat-mvp` — family/admin chat app (role-aware, per-user isolation)
- `apps/mission-control` — operator dashboard and deployable control plane
- `apps/prismbot-desktop` — Electron shell that starts local PrismBot stack
- `apps/prismbot-mobile` — Expo mobile app with local/hybrid/remote modes
- `apps/public-chat` — lightweight public web chat MVP
- `apps/prismbot-site` — conversion-focused landing page / waitlist funnel (Formspree + analytics-ready)
- `apps/pixel-pipeline` — image/animation production scaffold (generate → polish → package)
- `apps/prismbot-core` — unified consolidation target (single runtime/API/auth for all modules), including OmniAPI (`/api/omni/*`) and Studio pipeline APIs
- `apps/prismbot-wix` — imported Wix template/site code synced from `codysumpter-cloud/prismbot.wix`
- `apps/prismbot-web` — standalone no-Wix static website (conversion-focused)

## Phase 2 Power-Up (Completed)

- **Track 1:** Reliability autopilot (`scripts/bot-health.sh`, `scripts/bot-recover.sh`, `TROUBLESHOOTING_PHASE2.md`)
- **Track 2:** Memory quality system (`memory/decisions`, `memory/preferences`, weekly curation checklist)
- **Track 3:** Research citation mode (`RESEARCH_CITATION_MODE.md`, response template)

## One-Click Install (OpenClaw + PrismBot + OmniAPI)

### Linux (Ubuntu/Debian)

```bash
git clone https://github.com/codysumpter-cloud/PrismBot.git ~/.openclaw/workspace && cd ~/.openclaw/workspace && bash scripts/install-oneclick.sh
```

### Windows (PowerShell)

```powershell
git clone https://github.com/codysumpter-cloud/PrismBot.git "$env:USERPROFILE\.openclaw\workspace"; cd "$env:USERPROFILE\.openclaw\workspace"; powershell -ExecutionPolicy Bypass -File .\scripts\install-oneclick.ps1
```

### Keep all repos up to date (one command)

Linux:
```bash
bash scripts/update-all.sh
```

Windows:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\update-all.ps1
```

### Build a custom BMO installer ISO (Ubuntu autoinstall)

```bash
bash iso/build-bmo-installer-iso.sh \
  --ubuntu-iso ~/Downloads/ubuntu-24.04.2-desktop-amd64.iso \
  --output ~/Downloads/bmo-ubuntu-24.04.2-autoinstall.iso \
  --hostname bmo-node \
  --username bmo \
  --password 'ChangeMeNow!' \
  --with-gaming
```

### One-click download latest published BMO ISO

Linux:
```bash
bash scripts/download-latest-bmo-iso.sh ~/Downloads
```

Windows:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\download-latest-bmo-iso.ps1 -OutDir "$env:USERPROFILE\Downloads"
```

See `iso/README.md` for notes and caveats.

What this provisions:
- OpenClaw CLI
- PrismBot Core dependencies
- `omni-bmo` repo sync (`be-more-agent/`)
- (Linux) Local Image Backend Python venv + deps
- (Linux) systemd user services (`prismbot-core`, `prismbot-local-image`, optional `prismbot-bridge`)
- Gateway startup + verification

Env template is created at:
- Linux: `~/.config/prismbot-core.env`
- Windows: `%USERPROFILE%\.config\prismbot-core.env`

## Git Setup

Remote:
- `origin` → `https://github.com/codysumpter-cloud/PrismBot.git`

## Website Build Standard

- `WEBSITE_FACTORY_STANDARD.md` defines the default Wix-like build quality for all requested websites.
- `apps/prismbot-site/website-template-wixlike.html` provides a reusable high-conversion page scaffold.

## OmniAPI (Newest)

PrismBot now includes a unified OmniAPI surface in `apps/prismbot-core`.

- Base routes: `/api/omni/*`
- Key routes: `/api/omni/health`, `/api/omni/capabilities`, `/api/omni/backends`, `/api/omni/models`, `/api/omni/orchestrate/*`, `/api/omni/audio/*`
- Wave 1 model-sovereignty: pluggable provider adapters (`openai`, `anthropic`, `google`, `xai`, `ollama`, `local`) with deterministic routing strategies (`quality|latency|cost|local-first`) and explicit readiness states (`ready|missing_credentials|disabled|error`)
- Studio integration: `/api/studio/generate-image`, `/api/studio/edit-image`, `/api/studio/local-image-health`
- Public asset URL shaping: configure `CORE_PUBLIC_ASSET_BASE_URL` for production-facing artifact links (`publicUrl`) without exposing internal filesystem paths
- Spec files:
  - `apps/prismbot-core/OMNIAI_FULL_SPEC.md`
  - `apps/prismbot-core/OMNIAI_OPENAPI_FULL.yaml`

## Notes

Sensitive runtime/state files are excluded via `.gitignore`.
