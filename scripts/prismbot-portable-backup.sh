#!/usr/bin/env bash
set -euo pipefail

# Creates a portable backup bundle for PrismBot/OpenClaw.
# Safe by default: read-only source, write-only destination.

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${1:-$HOME/prismbot-backups}"
BUNDLE_DIR="$OUT_DIR/prismbot-$STAMP"
ARCHIVE="$OUT_DIR/prismbot-$STAMP.tgz"

OPENCLAW_HOME="${OPENCLAW_HOME:-$HOME/.openclaw}"
WORKSPACE="${WORKSPACE:-$HOME/.openclaw/workspace}"

mkdir -p "$BUNDLE_DIR"

echo "[1/5] Capturing runtime metadata..."
{
  echo "timestamp_utc=$STAMP"
  echo "hostname=$(hostname)"
  echo "openclaw_home=$OPENCLAW_HOME"
  echo "workspace=$WORKSPACE"
  echo "kernel=$(uname -srmo)"
  echo "node=$(node -v 2>/dev/null || true)"
  echo "openclaw_version=$(openclaw --version 2>/dev/null || true)"
} > "$BUNDLE_DIR/manifest.txt"

if command -v openclaw >/dev/null 2>&1; then
  echo "[2/5] Capturing openclaw status..."
  openclaw status > "$BUNDLE_DIR/openclaw-status.txt" || true
fi

echo "[3/5] Syncing workspace..."
rsync -a --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  "$WORKSPACE/" "$BUNDLE_DIR/workspace/"

echo "[4/5] Syncing ~/.openclaw (filtered)..."
rsync -a \
  --exclude 'tmp' \
  --exclude 'cache' \
  --exclude '*.sock' \
  "$OPENCLAW_HOME/" "$BUNDLE_DIR/openclaw-home/"

echo "[5/5] Creating compressed archive..."
tar -C "$OUT_DIR" -czf "$ARCHIVE" "$(basename "$BUNDLE_DIR")"

sha256sum "$ARCHIVE" > "$ARCHIVE.sha256"

cat <<EOF

Backup complete.
Archive: $ARCHIVE
Checksum: $ARCHIVE.sha256

To restore on a new host:
  mkdir -p ~/.openclaw
  tar -xzf "$ARCHIVE" -C /tmp
  rsync -a /tmp/$(basename "$BUNDLE_DIR")/openclaw-home/ ~/.openclaw/
  rsync -a /tmp/$(basename "$BUNDLE_DIR")/workspace/ ~/.openclaw/workspace/

EOF
