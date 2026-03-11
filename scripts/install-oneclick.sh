#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="${REPO_DIR:-$HOME/.openclaw/workspace}"
TEMPLATE_DIR="$REPO_DIR/templates"
SYSTEMD_USER_DIR="$HOME/.config/systemd/user"
CORE_ENV="$HOME/.config/prismbot-core.env"

echo "== PrismBot one-click install =="
echo "repo: $REPO_DIR"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  echo "[error] repo not found at $REPO_DIR"
  echo "Clone first: git clone https://github.com/codysumpter-cloud/PrismBot.git $REPO_DIR"
  exit 1
fi

if command -v sudo >/dev/null 2>&1; then
  SUDO="sudo"
else
  echo "[error] sudo required"
  exit 1
fi

echo "[1/9] Installing base packages"
$SUDO apt-get update -y
$SUDO apt-get install -y curl git jq python3 python3-venv python3-pip nodejs npm

echo "[2/9] Installing OpenClaw CLI"
$SUDO npm i -g openclaw

echo "[3/9] Syncing omni-bmo repo"
if [[ -d "$REPO_DIR/be-more-agent/.git" ]]; then
  ( cd "$REPO_DIR/be-more-agent" && git pull --ff-only || true )
else
  git clone https://github.com/codysumpter-cloud/omni-bmo.git "$REPO_DIR/be-more-agent" || true
fi

echo "[4/9] Installing Node deps"
( cd "$REPO_DIR/apps/prismbot-core" && npm install )
if [[ -f "$REPO_DIR/apps/prismbot-mobile/package.json" ]]; then
  ( cd "$REPO_DIR/apps/prismbot-mobile" && npm install || true )
fi

echo "[5/9] Setting up local image backend venv"
( cd "$REPO_DIR/apps/pixel-pipeline/local-image-backend" && \
  python3 -m venv .venv && \
  .venv/bin/pip install --upgrade pip && \
  .venv/bin/pip install -r requirements.txt )

echo "[6/9] Writing env template if missing"
mkdir -p "$HOME/.config"
if [[ ! -f "$CORE_ENV" ]]; then
  cp "$TEMPLATE_DIR/prismbot-core.env.example" "$CORE_ENV"
  echo "  created $CORE_ENV"
else
  echo "  keeping existing $CORE_ENV"
fi

echo "[7/9] Installing user systemd services"
mkdir -p "$SYSTEMD_USER_DIR"
cp "$TEMPLATE_DIR/systemd-user/prismbot-core.service" "$SYSTEMD_USER_DIR/prismbot-core.service"
cp "$TEMPLATE_DIR/systemd-user/prismbot-local-image.service" "$SYSTEMD_USER_DIR/prismbot-local-image.service"
if [[ -f "$REPO_DIR/apps/prismbot-mobile/.bridge.env" ]]; then
  cp "$TEMPLATE_DIR/systemd-user/prismbot-bridge.service" "$SYSTEMD_USER_DIR/prismbot-bridge.service"
fi
systemctl --user daemon-reload
systemctl --user enable --now prismbot-local-image.service prismbot-core.service
if [[ -f "$SYSTEMD_USER_DIR/prismbot-bridge.service" ]]; then
  systemctl --user enable --now prismbot-bridge.service || true
fi

echo "[8/9] Starting OpenClaw gateway"
openclaw gateway start || true

echo "[9/9] Post-install verification"
sleep 1
"$REPO_DIR/scripts/post-install-verify.sh" || true
openclaw status | sed -n '1,18p' || true

echo
echo "Install complete."
echo "Next: configure OpenClaw channels + auth if this is a fresh host."
echo "Secrets guide: edit $CORE_ENV then restart prismbot-core.service"
