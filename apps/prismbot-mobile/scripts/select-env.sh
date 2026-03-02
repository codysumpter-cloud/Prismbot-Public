#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"
case "$MODE" in
  dev|beta|prod) ;;
  *) echo "Usage: $0 {dev|beta|prod}"; exit 1 ;;
esac

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cp "$ROOT_DIR/env-presets/${MODE}.env.sample" "$ROOT_DIR/.env"
echo "Applied env-presets/${MODE}.env.sample -> .env"
