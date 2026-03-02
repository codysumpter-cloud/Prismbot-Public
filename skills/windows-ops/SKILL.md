---
name: windows-ops
description: Reliable Windows operations for OpenClaw hosts. Use when troubleshooting gateway startup, Scheduled Task behavior, PATH/tool resolution, shell execution policy issues, or host-level reliability on Windows.
---

# Windows Ops

Use deterministic, low-risk steps for Windows host operations.

## Core Workflow

1. Verify truth first:
   - `openclaw.cmd health`
   - `openclaw.cmd channels status --probe`
2. If unhealthy, apply minimal recovery:
   - `openclaw.cmd gateway restart`
   - wait 5s, re-check `openclaw.cmd health`
3. If still failing, check task/process state:
   - `schtasks /Query /TN "OpenClaw Gateway" /V /FO LIST`
   - `netstat -ano | findstr :18789`
4. Fix one variable at a time (task action, user context, PATH, token mismatch).

## Safety Rules

- Prefer `openclaw.cmd` commands over raw foreground gateway commands.
- Avoid destructive host-wide changes unless user explicitly asks.
- Back up config before edits.
- Treat `openclaw.cmd health` as liveness truth; task-state output can be noisy.

## References

- Read `references/recovery.md` for known-good recovery playbook.
- Read `references/safe-baseline.md` for security baseline settings.
