$ErrorActionPreference = 'Stop'

openclaw.cmd health | Out-Host
openclaw.cmd channels status --probe | Out-Host
