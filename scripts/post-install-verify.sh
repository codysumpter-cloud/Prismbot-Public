#!/usr/bin/env bash
set -euo pipefail

CORE_BASE="${CORE_BASE:-http://127.0.0.1:8799}"
TOKEN="${PRISMBOT_API_TOKEN:-}"

echo "== PrismBot post-install verify =="

check() {
  local name="$1" cmd="$2"
  if bash -lc "$cmd" >/dev/null 2>&1; then
    echo "[OK] $name"
  else
    echo "[FAIL] $name"
    return 1
  fi
}

check "Core API health" "curl -fsS ${CORE_BASE}/api/health >/dev/null"
check "Omni health" "curl -fsS ${CORE_BASE}/api/omni/health -H 'Authorization: Bearer ${TOKEN}' >/dev/null || curl -fsS ${CORE_BASE}/api/omni/health >/dev/null"
check "Local image backend" "curl -fsS http://127.0.0.1:7860/health >/dev/null"
check "Core service active" "systemctl --user is-active prismbot-core.service | grep -q active"
check "Local image service active" "systemctl --user is-active prismbot-local-image.service | grep -q active"

echo "\nSecrets onboarding checklist:"
echo "- Edit ~/.config/prismbot-core.env"
echo "- Set PRISMBOT_API_TOKEN (required for bearer clients)"
echo "- Add provider keys as available: OPENAI_API_KEY, ANTHROPIC_API_KEY, GOOGLE_API_KEY/GEMINI_API_KEY, XAI_API_KEY"
echo "- Optional local provider: OMNI_OLLAMA_HOST + OMNI_OLLAMA_MODEL"
echo "- Restart service: systemctl --user restart prismbot-core.service"
echo "- Verify backends: curl -sS ${CORE_BASE}/api/omni/backends -H 'Authorization: Bearer <token>' | jq ."
