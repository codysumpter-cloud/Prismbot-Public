# Audit Routine

1. `openclaw.cmd security audit`
2. If critical findings exist, classify as:
   - safe auto-fix
   - needs owner decision
3. Apply safe fixes only.
4. Validate with:
   - `openclaw.cmd health`
   - `openclaw.cmd channels status --probe`
