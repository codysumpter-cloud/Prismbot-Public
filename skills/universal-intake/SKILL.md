---
name: universal-intake
description: Read arbitrary user-provided links/files/media with best-effort fallbacks (web fetch, browser relay, yt-dlp, ffmpeg keyframes), then return actionable summaries and blockers. Use when a user says "read/watch this link" or provides media/assets for analysis.
---

# Universal Intake

Use this when users send links, videos, images, or shared pages and want analysis.

## Workflow

1. Try direct fetch first (`web_fetch`).
2. If blocked/auth-gated, use browser relay path (requires paired/attached browser tab).
3. For videos, run `scripts/extract_video_keyframes.sh <file-or-url>`.
4. Summarize with:
   - verdict
   - key points
   - timestamped highlights (if video)
   - concrete next actions
   - confidence + blockers

## Guardrails

- Never claim full access if link is auth/captcha-gated.
- Explicitly state what was accessible and what was blocked.
- Keep user-facing output practical and blunt.

## Scripts

- `scripts/extract_video_keyframes.sh` — probes media and extracts 1fps frames for quick review.
- `scripts/fetch_any_link.sh` — tries web fetch + yt-dlp metadata as fallback.
