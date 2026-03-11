param(
  [string]$RootDir = "$env:USERPROFILE\.openclaw\workspace"
)

$ErrorActionPreference = 'Stop'

function Log($msg) {
  Write-Host "[update-all] $msg"
}

function Sync-Repo {
  param(
    [string]$Name,
    [string]$Url,
    [string]$Dir
  )

  if (Test-Path "$Dir\.git") {
    Log "$Name: fetch"
    git -C "$Dir" fetch --all --prune

    $dirty = git -C "$Dir" status --porcelain
    if ($dirty) {
      Log "$Name: dirty tree, skipping pull (commit/stash first)"
      return
    }

    Log "$Name: pull --ff-only"
    git -C "$Dir" pull --ff-only
  }
  else {
    Log "$Name: clone"
    git clone "$Url" "$Dir"
  }
}

Log "root: $RootDir"

Sync-Repo -Name "PrismBot" -Url "https://github.com/codysumpter-cloud/PrismBot.git" -Dir "$RootDir"
Sync-Repo -Name "omni-bmo" -Url "https://github.com/codysumpter-cloud/omni-bmo.git" -Dir "$RootDir\be-more-agent"
Sync-Repo -Name "be-more-hailo" -Url "https://github.com/moorew/be-more-hailo.git" -Dir "$RootDir\be-more-hailo"

Log "done"
