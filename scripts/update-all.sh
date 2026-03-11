#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="${ROOT_DIR:-$HOME/.openclaw/workspace}"

log() { echo "[update-all] $*"; }

sync_repo() {
  local name="$1"
  local url="$2"
  local dir="$3"

  if [[ -d "$dir/.git" ]]; then
    log "$name: fetch"
    git -C "$dir" fetch --all --prune

    if [[ -n "$(git -C "$dir" status --porcelain)" ]]; then
      log "$name: dirty tree, skipping pull (commit/stash first)"
      return 0
    fi

    log "$name: pull --ff-only"
    git -C "$dir" pull --ff-only
  else
    log "$name: clone"
    git clone "$url" "$dir"
  fi
}

log "root: $ROOT_DIR"

sync_repo "PrismBot" "https://github.com/codysumpter-cloud/PrismBot.git" "$ROOT_DIR"
sync_repo "omni-bmo" "https://github.com/codysumpter-cloud/omni-bmo.git" "$ROOT_DIR/be-more-agent"
sync_repo "be-more-hailo" "https://github.com/moorew/be-more-hailo.git" "$ROOT_DIR/be-more-hailo"

log "done"
