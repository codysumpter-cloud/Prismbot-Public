#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$HOME/.openclaw/workspace/apps/prismbot-mobile/deploy"
UNIT_SRC="$SRC_DIR/prismbot-bridge.service"
UNIT_DST="$HOME/.config/systemd/user/prismbot-bridge.service"

mkdir -p "$HOME/.config/systemd/user"
cp "$UNIT_SRC" "$UNIT_DST"

systemctl --user daemon-reload
systemctl --user enable --now prismbot-bridge.service

echo "Installed + started user service: prismbot-bridge.service"
systemctl --user --no-pager status prismbot-bridge.service | sed -n '1,20p'
