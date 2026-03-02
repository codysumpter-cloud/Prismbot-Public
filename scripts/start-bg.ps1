$ErrorActionPreference = 'Stop'

openclaw.cmd gateway start | Out-Host
Start-Sleep -Seconds 3
openclaw.cmd gateway status | Out-Host
