# Recovery Playbook (Windows)

## Fast path

```powershell
openclaw.cmd gateway restart
Start-Sleep -Seconds 5
openclaw.cmd health
```

## If commands fail in PowerShell due to policy

Use `.cmd` shims (`openclaw.cmd`, `npm.cmd`) instead of `.ps1` wrappers.

## If port conflict exists

```powershell
netstat -ano | findstr :18789
```

Kill only the conflicting PID (not all processes).

## Validate

- Telegram/Discord should show OK in `openclaw.cmd health`.
- Confirm you can receive/reply in channel.
