# BOT_CHECKLIST.md — 2-Minute Startup + Recovery

## Startup

1. Start gateway (background):
   - `openclaw.cmd gateway start`
2. Verify channels:
   - `openclaw.cmd health`

Expected: Telegram OK + Discord OK.

## Recovery (if flaky)

1. `./scripts/bot-recover.ps1`
2. send a test message (`yo`) from Telegram/Discord

## Platform Response Rules

- Discord: custom emoji allowed (`<:Prismtek:1467294389133906103>`)
- Telegram: no Discord custom emoji tokens

## Safety Rules

- Never grant access to non-owner users
- Keep allowlists tight
- Avoid destructive/system-wide changes without explicit user approval

## Build/Work Focus

Current focus: polish PrismBot reliability + workflow quality (not Wildlands tasks unless explicitly requested).
