# TOOLS.md - Local Notes

Skills define _how_ tools work. This file is for _your_ specifics — the stuff that's unique to your setup.

## What Goes Here

Things like:

- Camera names and locations
- SSH hosts and aliases
- Preferred voices for TTS
- Speaker/room names
- Device nicknames
- Anything environment-specific

## Examples

```markdown
### Cameras

- living-room → Main area, 180° wide angle
- front-door → Entrance, motion-triggered

### SSH

- home-server → 192.168.1.100, user: admin

### TTS

- Preferred voice: "Nova" (warm, slightly British)
- Default speaker: Kitchen HomePod
```

## Why Separate?

Skills are shared. Your setup is yours. Keeping them apart means you can update skills without losing your notes, and share skills without leaking your infrastructure.

---

Add whatever helps you do your job. This is your cheat sheet.

### 3-Lane Mode (Enabled)

- Status: **enabled** (set by Prismtek on 2026-03-10)
- Goal: reduce token usage while preserving final-answer quality
- Lane routing:
  - **Scout lane (cheap):** quick triage, extraction, summarization
    - Preferred model: `ollama/llama3.2:1b` (fallback `ollama/llama3.2:3b`)
  - **Builder lane (mid):** implementation, drafting, transformations
    - Preferred model: `ollama/omni-core:phase3` (fallback `ollama/minimax-m2.5:cloud`)
  - **Judge lane (best):** final synthesis, high-stakes decisions, user-facing finish
    - Preferred model: `openai-codex/gpt-5.3-codex` (fallback `ollama/minimax-m2.5:cloud`)
- Operating rule: only invoke Judge lane for final pass or ambiguity/risk.

### PixelLab MCP (Hosted Pixel Art Generation)

- MCP endpoint: `https://api.pixellab.ai/mcp`
- Access path validated with `mcporter` (hosted, no local GPU needed)
- Primary runbook: `PIXELLAB-AGENT-FLOW.md`
- Best use: character sheets, animations, isometric tiles, top-down/sidescroller tilesets
- Pattern: `create_*` (async queue) -> `get_*` (status/result polling)

### Link/Media Intake Tooling

- `ffmpeg` + `ffprobe` installed for video probing + frame extraction
- `yt-dlp` installed for downloadable video metadata/media pulls when supported
- `jq` installed for quick JSON inspection in scripts
- Skill added: `skills/universal-intake/`
  - `scripts/fetch_any_link.sh`
  - `scripts/extract_video_keyframes.sh`

### Local Image Backend

- Local backend service: `prismbot-local-image` (systemd user service)
- Endpoints:
  - `http://127.0.0.1:7860/sdapi/v1/txt2img` (generate)
  - `http://127.0.0.1:7860/sdapi/v1/img2img` (edit/inpaint-style)
- Health: `http://127.0.0.1:7860/health`
- Code: `apps/pixel-pipeline/local-image-backend/server.py`
- Current mode: procedural pixel generator (offline/local), Studio uses it in `CORE_STUDIO_IMAGE_BACKEND=auto`.

### Prismtek Website Direct Ops

- Live WP root: `/var/www/prismtek-wordpress`
- Live MU plugin: `/var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php`
- Direct edit path works via local `sudo -n` + WP-CLI.
- Pre-edit sync script: `scripts/sync_prismtek_live.sh`
- Ops runbook: `WEBSITE-OPS.md`
