---
name: release-engineer
description: Build, package, validate, and ship stable release artifacts. Use when preparing distributable builds, creating zip packages, writing release notes, or preventing broken runtime drift.
---

# Release Engineer

Ship repeatable artifacts with verification.

## Workflow

1. Build from known-good source.
2. Run preflight checks (scripts/config/files present).
3. Package artifacts.
4. Verify checksum + smoke test.
5. Publish with concise release notes.

## Guardrails

- Never package from mixed stale outputs.
- Prefer frozen runtime artifacts for player bundles.
- Fail fast on missing scripts or required files.

## References

- `references/checklist.md` for release checklist.
- `references/notes-template.md` for release note format.
