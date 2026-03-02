# Setup

Required tools:

- `yt-dlp`
- `ffmpeg`

Optional (better results):

- `whisper` CLI for local speech-to-text

Quick checks:

```powershell
yt-dlp --version
ffmpeg -version
whisper --help
```

If `whisper` is missing, the skill still downloads video + samples keyframes, but transcript-based analysis will be limited.
