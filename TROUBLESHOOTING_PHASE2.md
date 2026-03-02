# Troubleshooting (Phase 2)

## Fast triage checklist

1. **Gateway alive?**
   - `openclaw gateway status`
2. **Overall status healthy?**
   - `openclaw status`
3. **Recent config edits?**
   - Verify `/home/cody_sumpter/.openclaw/openclaw.json`
4. **Channel-specific issue?**
   - Check provider auth/token and allowlists
5. **Recover quickly**
   - `./scripts/bot-recover.sh`

## Failure classes

- **Gateway not running**
  - Start/restart via `openclaw gateway start|restart`
- **Auth/provider errors**
  - Revalidate token/credentials and restart gateway
- **Rate-limits/transient API errors**
  - Backoff and retry after cool-down
- **Config mismatch**
  - Revert recent changes and restart

## Post-incident notes

Capture:
- Timestamp (UTC)
- Symptom
- Root cause
- Fix applied
- Prevention action
