#!/usr/bin/env bash
set -euo pipefail

BIN="$HOME/.local/bin/cloudflared"
STATE_DIR="$HOME/.openclaw/tunnels"
mkdir -p "$STATE_DIR"

start_tunnel() {
  local name="$1"
  local url="$2"
  local log="$STATE_DIR/${name}.log"
  local pidf="$STATE_DIR/${name}.pid"

  if [[ -f "$pidf" ]] && kill -0 "$(cat "$pidf")" 2>/dev/null; then
    echo "$name already running (pid $(cat "$pidf"))"
    return 0
  fi

  nohup "$BIN" tunnel --url "$url" --no-autoupdate >"$log" 2>&1 &
  echo $! >"$pidf"
  sleep 3

  local public
  public=$(grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" | head -n1 || true)
  echo "$name started: ${public:-pending (check $log)}"
}

if [[ ! -x "$BIN" ]]; then
  echo "cloudflared not found at $BIN"
  exit 1
fi

start_tunnel family http://127.0.0.1:8787
start_tunnel admin http://127.0.0.1:8790

echo

echo "Tunnel links:"
for name in family admin; do
  log="$STATE_DIR/${name}.log"
  public=$(grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" | head -n1 || true)
  echo "- $name: ${public:-pending}"
done
