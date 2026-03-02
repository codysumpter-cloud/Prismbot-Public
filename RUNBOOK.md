# RUNBOOK.md — PrismBot Known-Good Operations

## Daily Start (No Drama)

Use only one of these:

```powershell
openclaw.cmd gateway start
openclaw.cmd health
```

Or launch your hidden startup script/VBS.

## Never Use (Foreground Noise)

Do **not** run these unless intentionally debugging foreground mode:

```powershell
openclaw.cmd gateway
gateway.cmd
openclaw.cmd logs --follow
```

## Fast Health Check

```powershell
.\scripts\bot-health.ps1
```

(Equivalent raw commands: `openclaw.cmd health` + `openclaw.cmd channels status --probe`)

## If Replies Stop

```powershell
openclaw.cmd gateway restart
Start-Sleep -Seconds 5
openclaw.cmd health
```

If health is OK, system is live even if task-state output is noisy.

## Security Baseline

- Keep `gateway.bind = loopback`
- Keep Discord `groupPolicy = allowlist`
- Keep Telegram `allowFrom` restricted to owner ID
- Keep auth rate-limit enabled

## Git Hygiene

From repo root only (`C:\Users\cody_\.openclaw\workspace`):

```powershell
git status
git add -A
git commit -m "<message>"
git push
```
