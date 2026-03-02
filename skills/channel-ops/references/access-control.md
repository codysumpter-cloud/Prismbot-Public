# Access Control Checklist

1. Telegram:
   - dmPolicy: allowlist
   - allowFrom includes owner ID only
2. Discord:
   - groupPolicy: allowlist
   - approved user IDs only
3. Re-validate with:
   - `openclaw.cmd health`
   - `openclaw.cmd channels status --probe`
