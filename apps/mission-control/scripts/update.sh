#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

git pull --ff-only
docker compose build --pull
docker compose up -d --remove-orphans

echo "Updated Mission Control. Current status:"
docker compose ps
