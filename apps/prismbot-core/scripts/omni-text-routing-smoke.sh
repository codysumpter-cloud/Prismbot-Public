#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${OMNI_SMOKE_PORT:-8899}"
TOKEN="${PRISMBOT_API_TOKEN:-wave-smoke-token}"
BASE="http://127.0.0.1:${PORT}/api/omni"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

(
  cd "$ROOT"
  PORT="$PORT" \
  PRISMBOT_API_TOKEN="$TOKEN" \
  OMNI_OPENAI_ENABLED=true \
  OMNI_ANTHROPIC_ENABLED=true \
  OMNI_GOOGLE_ENABLED=true \
  OMNI_XAI_ENABLED=true \
  OPENAI_API_KEY='' ANTHROPIC_API_KEY='' GOOGLE_API_KEY='' XAI_API_KEY='' \
  node src/server.js
) >/tmp/omni-text-routing-smoke.log 2>&1 &
SERVER_PID=$!

for _ in {1..40}; do
  if curl -fsS "$BASE/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done

AUTH=(-H "Authorization: Bearer ${TOKEN}" -H 'content-type: application/json')

curl -fsS "${AUTH[@]}" -X POST "$BASE/generate" -d '{"type":"text","prompt":"hello from smoke"}' | grep -q '"ok":true'
curl -fsS "${AUTH[@]}" -X POST "$BASE/chat/completions" -d '{"messages":[{"role":"user","content":"ping"}]}' | grep -q '"type":"chat.completion"'
curl -fsS "${AUTH[@]}" -X POST "$BASE/responses" -d '{"input":"status check"}' | grep -q '"type":"response"'
curl -fsS "${AUTH[@]}" -X POST "$BASE/summarize" -d '{"text":"One. Two. Three."}' | grep -q '"type":"summary"'
curl -fsS "${AUTH[@]}" -X POST "$BASE/audio/speak" -d '{"text":"hello"}' | grep -q '"type":"audio"'

echo "omni-text-routing-smoke: ok"
