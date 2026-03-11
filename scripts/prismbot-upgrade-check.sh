#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== PrismBot Upgrade Check =="

a=0
check() {
  local p="$1"
  if [ -e "$p" ]; then
    echo "[PASS] $p"
  else
    echo "[FAIL] $p"
    a=$((a+1))
  fi
}

check "evals/promptfoo/promptfooconfig.yaml"
check "evals/promptfoo/promptfooredteam.yaml"
check ".github/workflows/promptfoo-evals.yml"
check "FOUNDATION/06-SUPERPOWERS-STYLE-WORKFLOW.md"
check "FOUNDATION/07-LEAN-AGENT-ROSTER.md"
check "FOUNDATION/08-AGENT-UPGRADE-MATRIX.md"
check "BRANCH-PLAYBOOKS/README.md"
check ".github/ISSUE_TEMPLATE/feature-task.yml"
check "prism-react-starter/src/pages/PixelLabPage.jsx"

if [ "$a" -eq 0 ]; then
  echo "All upgrade assets present."
  exit 0
else
  echo "$a missing item(s)."
  exit 1
fi
