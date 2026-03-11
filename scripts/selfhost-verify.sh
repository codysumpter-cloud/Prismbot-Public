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

check "PrismBot Core health (8799)" "curl -fsS http://127.0.0.1:8799/api/health >/dev/null"
check "Omni health route" "curl -fsS http://127.0.0.1:8799/api/omni/health >/dev/null"
check "Local image backend (7860)" "curl -fsS http://127.0.0.1:7860/health >/dev/null"
check "Core service" "systemctl --user is-active prismbot-core.service | grep -q active"
check "Local image service" "systemctl --user is-active prismbot-local-image.service | grep -q active"

echo "Verification complete."
