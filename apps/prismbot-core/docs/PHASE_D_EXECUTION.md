# Phase D Execution (Decommission + Parity)

## Objective
Retire multi-app sprawl safely after confirming PrismBot Core parity.

## Completed in this phase

- Added cross-app parity checklist.
- Added legacy app archive policy + wrappers strategy.
- Added archive markers in legacy app folders.
- Added thin compatibility wrapper scripts.
- Added rollback plan document.

## Decommission rules

1. No hard deletions until parity checklist is fully green.
2. Keep compatibility wrappers for desktop/mobile entrypoints while users transition.
3. Archive old app folders as read-only references once parity is confirmed.
4. Keep migration scripts and historical docs in-repo.

## Exit criteria

- PrismBot Core is default runtime for web/desktop/mobile.
- Legacy app direct runtimes are no longer required for normal operation.
- Rollback path documented.
