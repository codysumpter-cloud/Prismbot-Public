#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${1:-$PWD}"
mkdir -p "$OUT_DIR"

ASSET_NAME="bmo-installer-latest.iso"
ASSET_URL="https://github.com/codysumpter-cloud/Prismbot-Public/releases/download/bmo-iso-latest/${ASSET_NAME}"

echo "Downloading: $ASSET_NAME"
curl -fL "$ASSET_URL" -o "$OUT_DIR/$ASSET_NAME"
echo "Saved: $OUT_DIR/$ASSET_NAME"
echo "Flash with Rufus/BalenaEtcher to your USB drive."
