param(
  [Parameter(Mandatory=$true)][string]$Url,
  [string]$OutDir = "./video-review",
  [int]$SampleSeconds = 20
)

$ErrorActionPreference = "Stop"

function Resolve-Cmd($name, $candidates=@()) {
  $cmd = Get-Command $name -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  foreach($c in $candidates){ if(Test-Path $c){ return $c } }
  return $null
}

$ytDlp = Resolve-Cmd "yt-dlp" @("$env:LOCALAPPDATA\Microsoft\WinGet\Links\yt-dlp.exe")
$ffmpeg = Resolve-Cmd "ffmpeg" @(
  "$env:LOCALAPPDATA\Microsoft\WinGet\Links\ffmpeg.exe",
  "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\yt-dlp.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-N-122609-g364d5dda91-win64-gpl\bin\ffmpeg.exe"
)
$whisperCmd = Resolve-Cmd "whisper" @("$env:LOCALAPPDATA\Programs\Python\Python311\Scripts\whisper.exe")

if(-not $ytDlp){ throw "Missing required command: yt-dlp" }
if(-not $ffmpeg){ throw "Missing required command: ffmpeg" }

# Ensure downstream tools (like whisper's internal ffmpeg call) can resolve ffmpeg.
$env:Path = "$(Split-Path $ffmpeg);$env:Path"

$slug = ($Url -replace '[^a-zA-Z0-9]+','-').Trim('-')
if ([string]::IsNullOrWhiteSpace($slug)) { $slug = "video" }
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$target = Join-Path $OutDir "$stamp-$slug"
$frames = Join-Path $target "frames"
New-Item -ItemType Directory -Force -Path $frames | Out-Null

$videoPath = Join-Path $target "video.mp4"
$audioPath = Join-Path $target "audio.wav"
$transcriptPath = Join-Path $target "transcript.txt"
$reviewPath = Join-Path $target "REVIEW.md"

Write-Host "Downloading video..."
& $ytDlp -f "bv*+ba/b" -o $videoPath $Url | Out-Host

Write-Host "Extracting audio..."
& $ffmpeg -y -i $videoPath -vn -ac 1 -ar 16000 $audioPath | Out-Host

Write-Host "Sampling keyframes..."
& $ffmpeg -y -i $videoPath -vf "fps=1/$SampleSeconds" "$frames/frame-%04d.jpg" | Out-Host

$transcribed = $false
if ($whisperCmd) {
  Write-Host "Transcribing with whisper CLI..."
  try {
    $env:PYTHONUTF8 = "1"
    & $whisperCmd $audioPath --model small --output_format txt --output_dir $target | Out-Host
    $maybe = Join-Path $target "audio.txt"
    if (Test-Path $maybe) {
      Move-Item -Force $maybe $transcriptPath
      $transcribed = $true
    }
  } catch {
    Write-Warning "Whisper transcription failed: $($_.Exception.Message)"
  }
}

$frameCount = (Get-ChildItem $frames -File -ErrorAction SilentlyContinue | Measure-Object).Count
$notes = @()
$notes += "# Video Review Prep"
$notes += ""
$notes += "- URL: $Url"
$notes += "- Folder: $target"
$notes += "- Video: $videoPath"
$notes += "- Frames: $frameCount sampled every $SampleSeconds sec"
$notes += "- Transcript: " + ($(if($transcribed){"available"}else{"not available (install whisper CLI for auto transcript)"}))
$notes += ""
$notes += "## Assistant next-step"
$notes += "Read this folder, inspect frames, read transcript (if present), and produce:"
$notes += "1) short summary"
$notes += "2) timestamped highlights"
$notes += "3) opinion/reaction"

Set-Content -Path $reviewPath -Value ($notes -join "`n") -Encoding UTF8
Write-Host "Done: $reviewPath"
