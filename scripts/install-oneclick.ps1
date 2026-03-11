param(
  [string]$RepoDir = "$env:USERPROFILE\.openclaw\workspace"
)

$ErrorActionPreference = 'Stop'

Write-Host "== PrismBot one-click install (Windows) =="
Write-Host "repo: $RepoDir"

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
  Write-Host "[1/8] Installing Git via winget"
  winget install --id Git.Git -e --silent --accept-source-agreements --accept-package-agreements | Out-Null
}

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
  Write-Host "[2/8] Installing Node.js LTS via winget"
  winget install --id OpenJS.NodeJS.LTS -e --silent --accept-source-agreements --accept-package-agreements | Out-Null
}

if (-not (Get-Command openclaw.cmd -ErrorAction SilentlyContinue)) {
  Write-Host "[3/8] Installing OpenClaw CLI"
  npm install -g openclaw | Out-Null
}

if (-not (Test-Path "$RepoDir\.git")) {
  Write-Host "[4/8] Cloning PrismBot repo"
  New-Item -ItemType Directory -Path (Split-Path $RepoDir -Parent) -Force | Out-Null
  git clone https://github.com/codysumpter-cloud/PrismBot.git "$RepoDir"
} else {
  Write-Host "[4/8] Updating existing PrismBot repo"
  git -C "$RepoDir" pull --ff-only
}

Write-Host "[5/8] Syncing omni-bmo repo"
$BmoDir = "$RepoDir\be-more-agent"
if (Test-Path "$BmoDir\.git") {
  git -C "$BmoDir" pull --ff-only
} else {
  git clone https://github.com/codysumpter-cloud/omni-bmo.git "$BmoDir"
}

Write-Host "[6/8] Installing PrismBot Core dependencies"
Push-Location "$RepoDir\apps\prismbot-core"
npm install
Pop-Location

Write-Host "[7/8] Writing env template if missing"
$CoreEnv = "$env:USERPROFILE\.config\prismbot-core.env"
New-Item -ItemType Directory -Path "$env:USERPROFILE\.config" -Force | Out-Null
if (-not (Test-Path $CoreEnv)) {
  Copy-Item "$RepoDir\templates\prismbot-core.env.example" $CoreEnv
  Write-Host "  created $CoreEnv"
} else {
  Write-Host "  keeping existing $CoreEnv"
}

Write-Host "[8/8] Starting OpenClaw gateway"
openclaw.cmd gateway start | Out-Null
openclaw.cmd status

Write-Host ""
Write-Host "Install complete."
Write-Host "Next: run 'openclaw.cmd configure' to connect providers/channels on fresh machines."