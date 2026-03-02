#!/usr/bin/env bash
set -euo pipefail

CFG="${OPENCLAW_CONFIG_PATH:-$HOME/.openclaw/openclaw.json}"
BACKUP_TOKEN_FILE="${OPENCLAW_DISCORD_BACKUP_TOKEN_FILE:-$HOME/.openclaw/discord-backup.token}"
PRIMARY_TOKEN_FILE="${OPENCLAW_DISCORD_PRIMARY_TOKEN_FILE:-$HOME/.openclaw/discord-primary.token}"

usage() {
  cat <<EOF
Usage: $0 <status|save-primary|to-backup|to-primary>

Commands:
  status       Show masked current token + whether token files exist
  save-primary Save current configured token as primary token file
  to-backup    Switch channels.discord.token to backup token file and restart gateway
  to-primary   Switch channels.discord.token to primary token file and restart gateway
EOF
}

mask() {
  local t="$1"
  local n=${#t}
  if (( n <= 8 )); then echo "****"; return; fi
  echo "${t:0:4}…${t:n-4:4}"
}

get_current_token() {
  node -e 'const fs=require("fs");const p=process.argv[1];const j=JSON.parse(fs.readFileSync(p,"utf8"));process.stdout.write((j.channels&&j.channels.discord&&j.channels.discord.token)||"");' "$CFG"
}

set_token() {
  local new_token="$1"
  node - <<'NODE' "$CFG" "$new_token"
const fs=require('fs');
const cfg=process.argv[2];
const token=process.argv[3];
const j=JSON.parse(fs.readFileSync(cfg,'utf8'));
j.channels=j.channels||{};
j.channels.discord=j.channels.discord||{};
j.channels.discord.enabled=true;
j.channels.discord.token=token;
if (j.channels.discord.accounts) delete j.channels.discord.accounts; // safety: avoid multi-account edge cases
fs.writeFileSync(cfg, JSON.stringify(j,null,2)+'\n');
NODE
}

cmd="${1:-}"
case "$cmd" in
  status)
    cur="$(get_current_token)"
    echo "Current token: $(mask "$cur")"
    [[ -f "$PRIMARY_TOKEN_FILE" ]] && echo "Primary token file: present" || echo "Primary token file: missing"
    [[ -f "$BACKUP_TOKEN_FILE" ]] && echo "Backup token file: present" || echo "Backup token file: missing"
    ;;
  save-primary)
    cur="$(get_current_token)"
    [[ -n "$cur" ]] || { echo "No current token in config" >&2; exit 1; }
    umask 077
    printf '%s' "$cur" > "$PRIMARY_TOKEN_FILE"
    echo "Saved current token to $PRIMARY_TOKEN_FILE"
    ;;
  to-backup)
    [[ -f "$BACKUP_TOKEN_FILE" ]] || { echo "Missing $BACKUP_TOKEN_FILE" >&2; exit 1; }
    tok="$(cat "$BACKUP_TOKEN_FILE")"
    [[ -n "$tok" ]] || { echo "Backup token file empty" >&2; exit 1; }
    set_token "$tok"
    openclaw gateway restart >/dev/null
    echo "Switched to backup token and restarted gateway."
    ;;
  to-primary)
    [[ -f "$PRIMARY_TOKEN_FILE" ]] || { echo "Missing $PRIMARY_TOKEN_FILE" >&2; exit 1; }
    tok="$(cat "$PRIMARY_TOKEN_FILE")"
    [[ -n "$tok" ]] || { echo "Primary token file empty" >&2; exit 1; }
    set_token "$tok"
    openclaw gateway restart >/dev/null
    echo "Switched to primary token and restarted gateway."
    ;;
  *)
    usage
    exit 1
    ;;
esac
