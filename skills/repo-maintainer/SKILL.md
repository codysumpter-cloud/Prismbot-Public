---
name: repo-maintainer
description: Maintain repository health with safe, incremental fixes. Use when diagnosing CI failures, resolving branch drift, improving script reliability, or preparing clean commits without breaking stable flows.
---

# Repo Maintainer

Keep repos shippable with minimal-risk changes.

## Workflow

1. Reproduce failure locally.
2. Identify smallest fix that unblocks.
3. Validate with the same command CI uses.
4. Commit with clear scope and message.
5. Push and verify status.

## Rules

- Prefer surgical edits over broad refactors.
- Do not change unrelated files in a hotfix.
- Keep one concern per commit.
- When uncertain, add guardrails (checks, preflight scripts).

## References

- `references/ci-triage.md`
- `references/commit-style.md`
