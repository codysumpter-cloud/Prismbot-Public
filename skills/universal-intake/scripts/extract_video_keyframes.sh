#!/usr/bin/env bash
set -euo pipefail

IN="${1:-}"
if [[ -z "$IN" ]]; then
  echo "Usage: $0 <video-file-or-url> [out-dir]" >&2
  exit 1
fi

OUT_DIR="${2:-/home/cody_sumpter/.openclaw/workspace/tmp/universal-intake}"
mkdir -p "$OUT_DIR"

SRC="$IN"
if [[ "$IN" =~ ^https?:// ]]; then
  yt-dlp -f "bv*+ba/b" --merge-output-format mp4 -o "$OUT_DIR/input.%(ext)s" "$IN"
  SRC="$(ls -1t "$OUT_DIR"/input.* | head -n1)"
fi

echo "Source: $SRC"
ffprobe -v error -show_entries format=duration:stream=index,codec_type,codec_name,width,height,avg_frame_rate -of default=noprint_wrappers=1 "$SRC"
ffmpeg -y -i "$SRC" -vf "fps=1" "$OUT_DIR/frame_%03d.jpg" >/dev/null 2>&1

echo "Frames:" 
ls -1 "$OUT_DIR"/frame_*.jpg | sed -n '1,120p'
