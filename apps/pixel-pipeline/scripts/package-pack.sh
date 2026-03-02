#!/usr/bin/env bash
set -euo pipefail
PACK_DIR=${1:-}
if [[ -z "$PACK_DIR" || ! -d "$PACK_DIR" ]]; then
  echo "Usage: $0 <pack_dir>"
  exit 1
fi

REL="$PACK_DIR/release"
mkdir -p "$REL"
cp -r "$PACK_DIR/sheets" "$REL/" 2>/dev/null || true
cp -r "$PACK_DIR/preview" "$REL/" 2>/dev/null || true

ZIP_NAME="$(basename "$PACK_DIR").zip"
(
  cd "$REL"
  zip -r "$ZIP_NAME" . >/dev/null
)

echo "Packaged: $REL/$ZIP_NAME"
