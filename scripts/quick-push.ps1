param(
  [string]$Message = "chore: quick update"
)

$ErrorActionPreference = 'Stop'

git add -A

# Commit only if there are staged changes
$staged = git diff --cached --name-only
if (-not $staged) {
  Write-Host "No staged changes to commit."
  exit 0
}

git commit -m $Message | Out-Host
git push | Out-Host
