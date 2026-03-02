$ErrorActionPreference = 'Stop'

Write-Host '=== PrismBot Health ==='
openclaw.cmd health | Out-Host
Write-Host "`n=== Channel Probe ==="
openclaw.cmd channels status --probe | Out-Host
