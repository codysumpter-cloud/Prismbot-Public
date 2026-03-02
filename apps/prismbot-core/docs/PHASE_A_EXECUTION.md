# Phase A Execution (Unified PrismBot)

## Completed in this phase

1. Core app workspace scaffolded (`apps/prismbot-core`)
2. Canonical schema drafted (`data/schema.v1.json`)
3. Migration scripts scaffolded for legacy imports
4. Auth/session middleware extraction path initialized
5. Feature freeze marker for legacy app folders

## Immediate next checks

- Run migration scripts in dry-run mode first
- Validate imported counts (users/sessions/tasks/history)
- Start porting auth/session code from kid-chat into core middleware
