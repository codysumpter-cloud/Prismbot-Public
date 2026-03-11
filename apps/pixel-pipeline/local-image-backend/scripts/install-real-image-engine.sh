#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VENV="$ROOT/.venv"
REQ="$ROOT/requirements-image-real.txt"

if [[ ! -x "$VENV/bin/pip" ]]; then
  echo "Missing venv at $VENV. Run initial backend setup first." >&2
  exit 1
fi

echo "Installing real image stack (torch + diffusers) ..."
"$VENV/bin/pip" install -r "$REQ"

echo "Done. Restart service: systemctl --user restart prismbot-local-image"
