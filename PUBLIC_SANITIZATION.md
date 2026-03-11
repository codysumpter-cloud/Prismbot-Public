# Public Repo Sanitization Notes

This public mirror excludes private/operational artifacts that are not needed for open collaboration.

Removed from public branch:
- `memory/` (session notes)
- `site-backups/` (live site snapshots)
- `tmp/` (temporary patch artifacts)
- `archive/` (retired/internal snapshots)
- `inbound/` (inbound media files)
- `buildlog-*.html` (internal build logs)
- `scripts/discord-failover.sh` (token file operational script)

If you need these internally, use the private repo.
