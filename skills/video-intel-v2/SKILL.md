---
name: video-intel-v2
description: Analyze videos from links/files using transcript + keyframe timeline. Use when the user asks you to watch a video, summarize key moments, extract highlights, or provide timestamped reactions.
---

# Video Intel v2

Produce practical video intelligence from URL to summary.

## Workflow

1. Run `scripts/video_intel.ps1 -Url <link>`.
2. Read generated outputs (`TIMELINE.md`, transcript, keyframes).
3. Reply with:
   - concise summary
   - top moments + timestamps
   - opinion/reaction
   - any confidence/coverage caveats

## Command

```powershell
./skills/video-intel-v2/scripts/video_intel.ps1 -Url "<video-url>"
```

## Requirements

- yt-dlp
- ffmpeg
- whisper CLI (optional but strongly recommended)

## References

- `references/output-format.md`
- `references/failure-modes.md`
