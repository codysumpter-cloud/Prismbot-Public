# Prismtek Website Ops (Model-Handoff Safe)

This runbook keeps website edits consistent across model switches/sessions.

## Live Site Path
- WordPress root: `/var/www/prismtek-wordpress`
- MU plugin: `/var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php`

## Access Method (Direct)
- Use local host access + `sudo -n` (no interactive password prompt) for live edits.
- WP-CLI works when run under sudo from WP root.

## Critical Page IDs / Slugs
- 54: `arcade-games`
- 55: `pixel-studio`
- 56: `community-center`
- 57: `school-safe`
- 67: `prism-creatures`
- 26: `pixel-arcade` (sections hub)
- 9: `build-log`

## Required Pre-Edit Sync
Before any live website change, run:

```bash
/home/cody_sumpter/.openclaw/workspace/scripts/sync_prismtek_live.sh
```

This captures:
- current live MU plugin
- current page index
- current key page content snapshot
- checksums

## After Edit Checklist
1. `php -l /var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php`
2. Verify key pages load:
   - `/arcade-games/`
   - `/pixel-studio/`
   - `/prism-creatures/`
3. Re-run sync script to create post-change backup.
4. Update workspace backup copy:
   - `site-backups/prismtek-pixel-vibes.latest.php`
5. Commit changes in workspace git.

## Split-Tab Contract
- Arcade Games tab must show game experience only.
- Pixel Studio tab must show studio tools only.
- Prism Creatures tab must show creature experience only.
- Avoid cross-tab bleed unless explicitly requested.
