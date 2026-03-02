#!/usr/bin/env bash
set -euo pipefail

check() {
  local name="$1"
  local cmd="$2"
  if bash -lc "$cmd" >/dev/null 2>&1; then
    echo "[OK] $name"
  else
    echo "[FAIL] $name"
  fi
}

check "Family app (8787)" "curl -fsS http://127.0.0.1:8787 >/dev/null"
check "Mission control (8790)" "curl -fsS http://127.0.0.1:8790/health >/dev/null"
check "Bridge health (8797)" "curl -fsS http://127.0.0.1:8797/api/mobile/health >/dev/null"
check "Tunnel status script" "test -x $HOME/.openclaw/workspace/scripts/tunnels-status.sh"

echo "Verification complete."
