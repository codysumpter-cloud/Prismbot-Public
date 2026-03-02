#!/usr/bin/env bash
set -euo pipefail

ROOT="$HOME/.openclaw/workspace"
OUT_DIR="$HOME/prismbot-migration"
mkdir -p "$OUT_DIR"

cd "$ROOT"

./scripts/prismbot-portable-backup.sh "$OUT_DIR"

LATEST_ARCHIVE="$(ls -1t "$OUT_DIR"/prismbot-*.tgz | head -n1)"
LATEST_SUM="${LATEST_ARCHIVE}.sha256"

cat > "$OUT_DIR/README-MIGRATION.txt" <<EOF
PrismBot migration bundle generated.
Archive: $LATEST_ARCHIVE
Checksum: $LATEST_SUM
Generated: $(date -u)
EOF

echo "Migration pack ready: $LATEST_ARCHIVE"
