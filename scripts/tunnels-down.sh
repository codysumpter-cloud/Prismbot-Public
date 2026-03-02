#!/usr/bin/env bash
set -euo pipefail

STATE_DIR="$HOME/.openclaw/tunnels"

for name in family admin; do
  pidf="$STATE_DIR/${name}.pid"
  if [[ -f "$pidf" ]]; then
    pid="$(cat "$pidf")"
    if kill -0 "$pid" 2>/dev/null; then
      kill "$pid" || true
      echo "Stopped $name (pid $pid)"
    fi
    rm -f "$pidf"
  fi
done
