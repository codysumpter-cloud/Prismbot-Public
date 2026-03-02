#!/usr/bin/env bash
set -euo pipefail

# Thin compatibility wrapper: always start unified core runtime.
cd "$(dirname "$0")/.."
exec node apps/prismbot-core/src/server.js
