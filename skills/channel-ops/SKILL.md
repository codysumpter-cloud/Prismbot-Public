---
name: channel-ops
description: Operate messaging channels safely across Telegram/Discord with strict access control. Use when managing allowlists, pairing, channel policies, delivery checks, and incident containment.
---

# Channel Ops

Keep channel messaging reliable and locked down.

## Workflow

1. Verify channel health (`openclaw.cmd health`).
2. Confirm policy/allowlists before changes.
3. Apply minimal channel config patch.
4. Re-check send/receive status.
5. Document what changed.

## Safety Rules

- Never broaden access without explicit owner instruction.
- Preserve owner-only constraints by default.
- Keep Discord group policy on allowlist unless user explicitly overrides.
- Keep Telegram allowFrom owner-only by default.

## References

- `references/access-control.md`
- `references/incident-response.md`
