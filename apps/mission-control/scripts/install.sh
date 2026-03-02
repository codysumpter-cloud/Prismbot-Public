#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example. Edit .env before exposing publicly."
fi

docker compose build
docker compose up -d

echo "Mission Control is starting. Check health:"
echo "  curl -fsS http://127.0.0.1:${PORT:-8790}/health"
