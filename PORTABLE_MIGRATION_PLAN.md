# PrismBot Portable + iPhone Transition Plan (No Downtime)

Goal: keep current Google VM live while you stand up an app-first workflow, then shut GCP down only when you decide.

## 0) Current state snapshot

Run on current host:

```bash
openclaw status
./scripts/prismbot-portable-backup.sh
```

This creates a timestamped backup archive + checksum.

---

## 1) Keep Google VM as source of truth (for now)

Do **not** cut over yet. Use VM as stable backend while iPhone app is validated.

Recommended now:

- Keep current Discord + channel config unchanged
- Keep gateway service running
- Add routine backups (daily or pre-change)

---

## 2) Build iPhone app as control surface first

Use app as frontend while VM still does heavy lifting.

App MVP should include:

- Login/session handling
- Chat UI + thread list
- Push notifications for inbound events
- "Server status" panel (latency, connected/disconnected)
- Fallback UX if backend is unavailable

This gives you immediate mobile-native UX without risky infra changes.

---

## 3) Private install options (no public App Store)

1. Xcode direct install (fastest)
2. TestFlight private testers (best practical private distribution)

Notes:

- Free Apple ID signing expires quickly (~7 days)
- Paid Apple Developer account is strongly preferred for consistent testing

---

## 4) Parallel-run cutover plan (when ready)

When you're ready to leave Google:

1. Provision new host (home server or alternative VPS)
2. Restore backup archive to new host
3. Validate with `openclaw status`
4. Keep Google VM online as fallback
5. Point app/backend endpoint to new host
6. Run both for 24-72 hours
7. If stable, decommission Google VM

Rollback: switch endpoint back to Google VM immediately.

---

## 5) Safety controls before final shutdown

Before powering off Google VM:

- Confirm backup restore tested on new host
- Confirm notifications and chat routing working from iPhone app
- Confirm at least one successful restart on new host
- Confirm no hardcoded Google IP/DNS remains in app config

---

## 6) Suggested immediate next command set

```bash
# On current Google VM
cd ~/.openclaw/workspace
./scripts/prismbot-portable-backup.sh

# Optional: inspect generated artifacts
ls -lah ~/prismbot-backups
```

If you want, next step is I generate a **host-to-host restore script** and a **pre-shutdown verification checklist** tailored to your exact destination host.
