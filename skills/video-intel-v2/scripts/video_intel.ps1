param(
  [Parameter(Mandatory=$true)][string]$Url,
  [string]$OutDir = "./video-intel",
  [int]$SampleSeconds = 15
)

$ErrorActionPreference = "Stop"

$reviewScript = "./skills/video-review/scripts/review_video.ps1"
if(-not (Test-Path $reviewScript)) {
  throw "Missing dependency script: $reviewScript"
}

# Reuse hardened downloader/transcriber pipeline from video-review skill.
powershell -NoProfile -ExecutionPolicy Bypass -File $reviewScript -Url $Url -OutDir $OutDir -SampleSeconds $SampleSeconds | Out-Host

# Build a lightweight timeline scaffold for assistant follow-up.
$latest = Get-ChildItem $OutDir -Directory | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if(-not $latest){ throw "No output directory found after run" }

$timeline = Join-Path $latest.FullName "TIMELINE.md"
$framesDir = Join-Path $latest.FullName "frames"
$frames = Get-ChildItem $framesDir -File -ErrorAction SilentlyContinue | Sort-Object Name

$lines = @()
$lines += "# Video Timeline Scaffold"
$lines += ""
$lines += "URL: $Url"
$lines += "Generated: $(Get-Date -Format s)"
$lines += ""
$lines += "## Keyframes"
$idx = 0
foreach($f in $frames){
  $ts = $idx * $SampleSeconds
  $lines += "- t=${ts}s -> $($f.Name)"
  $idx++
}
$lines += ""
$lines += "## Next Step"
$lines += "Read transcript + frames and produce summary + timestamped highlights."

Set-Content -Path $timeline -Value ($lines -join "`n") -Encoding UTF8
Write-Host "Done: $timeline"
