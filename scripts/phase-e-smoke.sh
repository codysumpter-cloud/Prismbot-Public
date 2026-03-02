#!/usr/bin/env bash
set -euo pipefail

BASE=${1:-http://127.0.0.1:8799}

echo "Smoke testing: $BASE"

check() {
  local path="$1"
  code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE$path")
  if [[ "$code" != "200" ]]; then
    echo "FAIL $path -> $code"
    exit 1
  fi
  echo "OK   $path"
}

check "/"
check "/chat"
check "/admin"
check "/public"
check "/app/chat"
check "/api/health"
check "/api/studio/status"

chat=$(curl -s -X POST "$BASE/api/chat" -H 'content-type: application/json' -d '{"text":"smoke"}')
echo "$chat" | grep -q '"ok":true' && echo "OK   /api/chat" || { echo "FAIL /api/chat"; exit 1; }

echo "Phase E smoke test passed."
