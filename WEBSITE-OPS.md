# Website Ops (Prismtek)

## Live Paths
- WP root: `/var/www/prismtek-wordpress`
- MU plugin: `/var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php`

## Critical Page IDs
- 54 `arcade-games`
- 55 `pixel-studio`
- 56 `community-center`
- 57 `school-safe`
- 67 `prism-creatures`
- 26 `pixel-arcade`
- 9  `build-log`

## Pre-edit backup
```bash
./scripts/sync_prismtek_live.sh
```

## Validation after edits
```bash
sudo -n php -l /var/www/prismtek-wordpress/wp-content/mu-plugins/prismtek-pixel-vibes.php
```

Then verify:
- `/arcade-games/`
- `/pixel-studio/`
- `/prism-creatures/`
- `/build-log/`

## Architecture contract
- Arcade, Studio, Creatures are separate focused tabs.
- Community Center is the primary mixed community surface.
- Avoid cross-feature bleed unless Prismtek asks for it.
