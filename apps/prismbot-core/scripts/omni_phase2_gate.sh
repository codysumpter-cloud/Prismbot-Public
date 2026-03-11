#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="$ROOT/docs/evidence/omni-parity-report.v1.1.json"
PACK="$ROOT/benchmarks/omni-parity-pack.v1.1.json"
THRESHOLD="${OMNI_PHASE2_GATE_MIN_PERCENT:-90}"

cd "$ROOT"
./scripts/omni_parity_eval.py --pack "$PACK" --out "$REPORT"

PERCENT=$(python3 - <<'PY'
import json
from pathlib import Path
p=Path('docs/evidence/omni-parity-report.v1.1.json')
r=json.loads(p.read_text())
print(r['overall']['percent'])
PY
)

PASS=$(python3 - <<PY
p=float("$PERCENT")
t=float("$THRESHOLD")
print('1' if p>=t else '0')
PY
)

echo "[phase2-gate] parity=${PERCENT}% threshold=${THRESHOLD}%"
if [[ "$PASS" != "1" ]]; then
  echo "[phase2-gate] FAIL"
  exit 1
fi

echo "[phase2-gate] PASS"
