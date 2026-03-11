#!/usr/bin/env bash
set -euo pipefail

BASE="${OMNI_BASE_URL:-http://127.0.0.1:8799/api/omni}"
TOKEN="${PRISMBOT_API_TOKEN:-${OMNI_API_TOKEN:-}}"
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_JSON="${OMNI_PIXEL_SMOKE_OUT:-/tmp/omni-pixel-zoomquilt-smoke.json}"

AUTH_ARGS=()
if [[ -n "$TOKEN" ]]; then
  AUTH_ARGS=(-H "Authorization: Bearer ${TOKEN}")
fi

curl -fsS -X POST "${BASE}/images/zoomquilt" \
  -H 'content-type: application/json' \
  "${AUTH_ARGS[@]}" \
  -d '{
    "prompt":"pixel cathedral corridor with repeating neon sigil infinite zoom",
    "size":512,
    "layers":6,
    "anchorMotif":"neon eye sigil",
    "pixelMode":"hq",
    "strictHq":false,
    "paletteLock":true,
    "paletteSize":16,
    "nearestNeighbor":true,
    "antiMush":true,
    "coherenceChecks":true,
    "coherenceThreshold":0.3
  }' > "$OUT_JSON"

node - "$OUT_JSON" "$ROOT/apps/pixel-pipeline/output" <<'NODE'
const fs = require('fs');
const path = require('path');
const [,, jsonPath, outRoot] = process.argv;
const raw = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
if (!raw.ok) throw new Error(`request_failed:${raw.error || 'unknown'}`);
const data = raw.data || raw;
if (!Array.isArray(data.frames) || data.frames.length < 4) throw new Error('missing_frames');
if (!data.previewWebPath) throw new Error('missing_preview');
const toAbs = (web) => path.join(outRoot, web.replace(/^\/studio-output\//, ''));
for (const w of data.frames) {
  const abs = toAbs(w);
  if (!fs.existsSync(abs)) throw new Error(`missing_frame_file:${abs}`);
}
const previewAbs = toAbs(data.previewWebPath);
if (!fs.existsSync(previewAbs)) throw new Error(`missing_preview_file:${previewAbs}`);
console.log('omni-pixel-zoomquilt-smoke: ok');
console.log(`frames=${data.frames.length}`);
console.log(`preview=${previewAbs}`);
NODE
