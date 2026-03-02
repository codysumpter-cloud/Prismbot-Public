#!/usr/bin/env bash
set -euo pipefail

STATE_DIR="$HOME/.openclaw/tunnels"
mkdir -p "$STATE_DIR"

for name in family admin; do
  pidf="$STATE_DIR/${name}.pid"
  log="$STATE_DIR/${name}.log"
  status="down"
  pid="-"
  if [[ -f "$pidf" ]]; then
    pid="$(cat "$pidf")"
    if kill -0 "$pid" 2>/dev/null; then
      status="up"
    fi
  fi
  link="$(grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" 2>/dev/null | head -n1 || true)"
  echo "$name: $status pid=$pid link=${link:-n/a}"
done
