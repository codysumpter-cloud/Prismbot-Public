# PrismBot Branch Organization

This repo now uses a branch map so work stays organized by track.

## Long-lived branches

- `main` — stable integration branch
- `dev/prismtek-framework` — docs/framework/instructions
- `feat/prompt-forge` — prompt builder app track
- `feat/pixel-pets-arena` — tamagotchi/battle game track
- `feat/arcade-streamer-hub` — arcade + leaderboard + streamer mode track
- `feat/private-ai-roadmap` — private AI/modeling stack track
- `feat/react-starter` — React/Next starter + routing/tooling track
- `feat/prismbot-core` — core runtime/API/data track
- `feat/pixel-pipeline` — pixel generation pipeline track
- `feat/prismbot-web-platform` — unified web platform track
- `feat/mission-control` — dashboard/orchestration track
- `feat/prismbot-mobile` — mobile app track
- `feat/prismbot-desktop` — desktop app track
- `feat/prismbot-wix-integration` — Wix integration track

## Workflow rules

1. Open issue -> choose branch -> implement small PR.
2. Keep PRs scoped to one feature/theme.
3. Merge into `main` only when:
   - build passes
   - docs updated
   - rollback path exists

## Branch naming for short-lived branches

- `feat/<topic>`
- `fix/<topic>`
- `docs/<topic>`
- `chore/<topic>`

## Commit style

- `feat: ...`
- `fix: ...`
- `docs: ...`
- `chore: ...`

## Suggested first milestones

- Prompt Forge MVP
- Pixel Pets vertical slice
- Arcade leaderboard alpha
- Private AI Phase 1 dataset pipeline
