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
- `apps/prismbot-core` — unified consolidation target (single runtime/API/auth for all modules)
- `apps/prismbot-wix` — imported Wix template/site code synced from `codysumpter-cloud/prismbot.wix`

## Phase 2 Power-Up (Completed)

- **Track 1:** Reliability autopilot (`scripts/bot-health.sh`, `scripts/bot-recover.sh`, `TROUBLESHOOTING_PHASE2.md`)
- **Track 2:** Memory quality system (`memory/decisions`, `memory/preferences`, weekly curation checklist)
- **Track 3:** Research citation mode (`RESEARCH_CITATION_MODE.md`, response template)

## Git Setup

Remote:
- `origin` → `https://github.com/codysumpter-cloud/PrismBot.git`

## Website Build Standard

- `WEBSITE_FACTORY_STANDARD.md` defines the default Wix-like build quality for all requested websites.
- `apps/prismbot-site/website-template-wixlike.html` provides a reusable high-conversion page scaffold.

## Latest Runtime Updates (2026-03-06)

- Omni Telegram bridge upgraded with:
  - `/status` backend/model checks
  - `/image <prompt>` generation flow (with async job polling + media send)
  - cleaner replies (reduced echo/quote noise)
- Omni image fallback chain expanded to improve reliability:
  - NanoBanana API
  - Gemini image fallback
  - Pollinations fallback
- PixelLab MCP tooling confirmed and bridged with command flow support (`/character`, `/animate`, `/tileset`, `/pixstatus`) in Omni Telegram runtime.
- Public one-click installer template maintained separately at:
  - `https://github.com/codysumpter-cloud/omni-openclaw-starter`

## Notes

Sensitive runtime/state files are excluded via `.gitignore`.
