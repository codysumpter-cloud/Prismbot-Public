#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <domain>"
  exit 1
fi

DOMAIN="$1"
CONF_SRC="$HOME/.openclaw/workspace/apps/prismbot-mobile/deploy/nginx-prismbot-bridge.conf"
CONF_TMP="/tmp/nginx-prismbot-bridge.conf"
CONF_DST="/etc/nginx/sites-available/prismbot-bridge"

if ! command -v nginx >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y nginx certbot python3-certbot-nginx
fi

sed "s/YOUR_DOMAIN/${DOMAIN}/g" "$CONF_SRC" > "$CONF_TMP"
sudo cp "$CONF_TMP" "$CONF_DST"
sudo ln -sf "$CONF_DST" /etc/nginx/sites-enabled/prismbot-bridge
sudo nginx -t
sudo systemctl restart nginx

# Obtain/renew cert and patch nginx automatically
sudo certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m admin@"$DOMAIN" --redirect || true
sudo systemctl reload nginx

echo "Nginx + TLS configured for $DOMAIN"
