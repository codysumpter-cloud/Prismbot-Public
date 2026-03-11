#!/usr/bin/env bash
set -euo pipefail
URL="${1:-}"
if [[ -z "$URL" ]]; then
  echo "Usage: $0 <url>" >&2
  exit 1
fi

echo "== HEAD =="
curl -I -L --max-time 20 "$URL" || true

echo "\n== yt-dlp metadata (if media/share-compatible) =="
yt-dlp --dump-single-json --skip-download "$URL" 2>/dev/null | jq '{title,webpage_url,duration,channel,uploader,upload_date}' || echo "yt-dlp metadata unavailable"
