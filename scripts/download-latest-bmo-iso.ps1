param(
  [string]$OutDir = "."
)

$ErrorActionPreference = 'Stop'
$assetName = "bmo-installer-latest.iso"
$assetUrl = "https://github.com/codysumpter-cloud/Prismbot-Public/releases/download/bmo-iso-latest/$assetName"

New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

$outPath = Join-Path $OutDir $assetName
Write-Host "Downloading: $assetName"
Invoke-WebRequest -Uri $assetUrl -OutFile $outPath
Write-Host "Saved: $outPath"
Write-Host "Flash with Rufus/BalenaEtcher to your USB drive."
