# Legacy App Archive Policy

These app folders are in archive transition while PrismBot Core becomes the single runtime:

- `apps/kid-chat-mvp`
- `apps/mission-control`
- `apps/public-chat`
- `apps/prismbot-site`
- `apps/pixel-pipeline`

Client apps stay active but must target Core APIs:
- `apps/prismbot-desktop`
- `apps/prismbot-mobile`

## Rules

- No new standalone backend feature work in archived folders.
- Only bugfix/security/migration-support changes allowed.
- New feature work belongs in `apps/prismbot-core`.
