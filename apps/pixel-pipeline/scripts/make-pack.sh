#!/usr/bin/env bash
set -euo pipefail

THEME=${1:-"sample-theme"}
SIZE=${2:-32}
COUNT=${3:-4}
TS=$(date -u +%Y%m%d-%H%M%S)
PACK_ID=$(echo "$THEME" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-//;s/-$//')-${SIZE}px-${TS}
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$BASE_DIR/output/$PACK_ID"

mkdir -p "$OUT"/{raw,polished,sheets,preview,release,docs}

cat > "$OUT/docs/spec.md" <<SPEC
# Pack Spec
- Theme: $THEME
- Size: ${SIZE}x${SIZE}
- Unit count target: $COUNT
- Required anims: idle, walk, attack, hit, death
SPEC

cp "$BASE_DIR/templates/README_ASSET_PACK.md" "$OUT/release/README.md"
cp "$BASE_DIR/templates/LICENSE_COMMERCIAL.txt" "$OUT/release/LICENSE.txt"

cat > "$OUT/docs/todo.md" <<TODO
# Operator TODO
1. Generate $COUNT base units with prompt pack in prompts/
2. Save source PNGs into raw/
3. Open and polish in LibreSprite -> polished/
4. Export sheets -> sheets/
5. Create preview GIFs -> preview/
6. Zip release bundle from release/ + sheets/ + preview/
TODO

echo "Created pack scaffold: $OUT"
echo "Next: fill raw/ then run ./scripts/package-pack.sh '$OUT'"
