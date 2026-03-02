$ErrorActionPreference = 'Stop'

Write-Host 'Restarting gateway...'
openclaw.cmd gateway restart | Out-Host
Start-Sleep -Seconds 5
Write-Host "`nRe-checking health..."
openclaw.cmd health | Out-Host
