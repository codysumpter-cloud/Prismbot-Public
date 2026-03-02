# Thin compatibility wrapper: always start unified core runtime.
$root = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $root
node apps/prismbot-core/src/server.js
