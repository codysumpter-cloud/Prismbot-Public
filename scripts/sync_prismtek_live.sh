#!/usr/bin/env bash
set -euo pipefail

TS="$(date -u +%Y%m%d-%H%M%SZ)"
ROOT="/home/cody_sumpter/.openclaw/workspace"
OUT="$ROOT/site-backups/live-$TS"
mkdir -p "$OUT"

sudo -n cp /var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php "$OUT/prismtek-pixel-vibes.php"

sudo -n bash -lc 'cd /var/www/prismtek-wordpress && wp post list --post_type=page --fields=ID,post_name,post_title --format=json --allow-root' > "$OUT/pages-index.json"

sudo -n bash -lc 'cd /var/www/prismtek-wordpress && for id in 54 55 56 57 67 26 9; do echo "---ID:$id---"; wp post get $id --field=post_content --allow-root; echo; done' > "$OUT/pages-content-snapshot.txt"

sha256sum "$OUT/prismtek-pixel-vibes.php" > "$OUT/SHA256SUMS.txt"
sha256sum "$OUT/pages-index.json" >> "$OUT/SHA256SUMS.txt"
sha256sum "$OUT/pages-content-snapshot.txt" >> "$OUT/SHA256SUMS.txt"

echo "Synced live website state to: $OUT"