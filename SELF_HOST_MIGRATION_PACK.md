# PrismBot Self-Host Migration Pack (Parallel-Safe)

This pack helps you move off Google VM later without downtime.

## Included assets
- `scripts/prismbot-portable-backup.sh` (already in repo)
- `scripts/selfhost-migration-pack.sh` (new)
- `scripts/selfhost-verify.sh` (new)

## Workflow
1. Generate migration pack on current VM.
2. Copy archive to new host.
3. Restore + run verification.
4. Run in parallel with Google VM for 24-72h.
5. Shut down Google only when ready.

## 1) Generate migration pack
```bash
cd ~/.openclaw/workspace
./scripts/selfhost-migration-pack.sh
```

## 2) Verify current host baseline
```bash
./scripts/selfhost-verify.sh
```

## 3) On new host
- Extract archive
- Restore `~/.openclaw` contents
- Start services and run `./scripts/selfhost-verify.sh`

## Success criteria
- Family app responds
- Mission control responds
- Bridge health endpoint responds
- Tunnel scripts start/stop cleanly

Rollback is immediate: continue using Google VM endpoints.
