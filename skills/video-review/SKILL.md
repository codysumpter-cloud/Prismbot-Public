---
name: video-review
description: Review and summarize online videos (especially YouTube) by extracting transcript + keyframes with local tools. Use when the user asks you to "watch" a video link, react to a video, summarize a video, or extract highlights/timestamps.
---

# Video Review

Use this skill when the user wants a real video breakdown instead of metadata-only comments.

## Workflow

1. Run `scripts/review_video.ps1` with the target URL.
2. Read the generated `REVIEW.md`.
3. Reply with:
   - 3-6 bullet summary
   - best moments with timestamps
   - your opinion/take
   - any missing capability note (if transcription failed)

## Command

```powershell
./skills/video-review/scripts/review_video.ps1 -Url "<video-url>"
```

Optional:

```powershell
./skills/video-review/scripts/review_video.ps1 -Url "<video-url>" -OutDir "./video-review" -SampleSeconds 20
```

## Output Location

- `video-review/<id>/REVIEW.md`
- `video-review/<id>/transcript.txt` (if available)
- `video-review/<id>/frames/`

## Notes

- Requires `yt-dlp` + `ffmpeg` in PATH.
- Uses `whisper` CLI if available for local transcription.
- If transcript is unavailable, still provide visual/keyframe-based observations and clearly say transcription was unavailable.
