#!/usr/bin/env bash
set -euo pipefail

echo "[1/4] Restarting gateway service..."
openclaw gateway restart

echo "[2/4] Waiting for warm-up..."
sleep 5

echo "[3/4] Checking gateway status..."
openclaw gateway status || true

echo "[4/4] Checking overall OpenClaw status..."
openclaw status || true

echo "Recovery pass complete. If issues persist, run: openclaw gateway status && openclaw status"
