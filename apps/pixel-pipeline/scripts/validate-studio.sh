#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKSPACE="$(cd "$ROOT/../.." && pwd)"
CORE="$WORKSPACE/apps/prismbot-core/src/server.js"
PARITY="$ROOT/STUDIO_PARITY_MATRIX.md"

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*"; exit 1; }

[[ -f "$CORE" ]] || fail "prismbot-core server.js not found"
[[ -x "$ROOT/scripts/make-pack.sh" ]] || fail "make-pack.sh missing or not executable"
[[ -x "$ROOT/scripts/package-pack.sh" ]] || fail "package-pack.sh missing or not executable"
[[ -f "$PARITY" ]] || fail "STUDIO_PARITY_MATRIX.md missing"

mkdir -p "$ROOT/output"
pass "Required files/scripts present"

if node --check "$CORE" >/dev/null 2>&1; then
  pass "Node syntax check passed"
else
  fail "Node syntax check failed"
fi

if curl -fsS --max-time 5 http://127.0.0.1:8799/api/health >/dev/null 2>&1; then
  pass "prismbot-core health endpoint reachable"
else
  fail "prismbot-core health endpoint unreachable"
fi

echo "Validation complete."
