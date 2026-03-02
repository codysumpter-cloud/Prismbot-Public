# CI Triage

1. Find exact failing job/step.
2. Run equivalent command locally.
3. Capture first real error (ignore downstream cascades).
4. Patch smallest root cause.
5. Re-run build/test script.
6. Commit + push.
