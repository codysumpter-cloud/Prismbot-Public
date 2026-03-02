# Focused Cleanup Pass Summary

## Completed in repo
- Added full teardown + upgrade blueprint (`TEARDOWN_UPGRADE_PLAN.md`).
- Identified page set to keep vs de-prioritize.
- Added execution phases for rapid conversion improvements.

## Why no hard delete yet
Wix page code files are tied to page IDs. Deleting files directly in git before removing pages in Wix Editor can cause mismatches and re-generation noise.

## Next operator actions (in Wix Editor)
1. Hide non-core template pages from nav.
2. Set Home as conversion-first single-page funnel.
3. Wire waitlist + book-call CTAs and event labels.
4. Publish and run QA checklist.
