---
name: security-guardian
description: Continuous security hardening and exposure control for local OpenClaw deployments. Use when auditing access, validating allowlists, checking risky config drift, or applying safe security baselines without breaking current runtime.
---

# Security Guardian

Keep the host secure without disrupting live operation.

## Workflow

1. Run security audit and summarize critical/warn findings.
2. Apply only safe, reversible hardening by default.
3. Avoid changes that can lock out the owner unless explicitly approved.
4. Re-check health/channels after any change.

## Safe Defaults

- Keep gateway loopback-only unless user asks for remote exposure.
- Keep group policies on allowlist.
- Keep auth rate-limits enabled.
- Keep owner-only access constraints intact.

## Guardrails

- Back up config before risky edits.
- Prefer surgical patches over full config replacement.
- Report exactly what changed and what remains.

## References

- `references/baseline.md`
- `references/audit-routine.md`
