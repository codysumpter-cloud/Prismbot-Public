#!/usr/bin/env bash
set -euo pipefail

BASE="${OMNI_BASE_URL:-http://127.0.0.1:8799/api/omni}"
TOKEN="${PRISMBOT_API_TOKEN:-}"
OUT="${OMNI_MATRIX_OUT:-/tmp/omni-live-matrix-$(date +%s).txt}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [[ -z "$TOKEN" ]]; then
  echo "PRISMBOT_API_TOKEN is required" >&2
  exit 1
fi

AUTH=(-H "Authorization: Bearer ${TOKEN}" -H 'content-type: application/json')
pass=0
fail=0

check() {
  local name="$1" method="$2" path="$3" data="${4:-}"
  local body status
  if [[ "$method" == "GET" ]]; then
    body=$(curl -sS -w "\n%{http_code}" -X GET "$BASE$path" -H "Authorization: Bearer ${TOKEN}")
  else
    body=$(curl -sS -w "\n%{http_code}" -X "$method" "$BASE$path" "${AUTH[@]}" --data "$data")
  fi
  status=$(echo "$body" | tail -n1)
  payload=$(echo "$body" | sed '$d')
  if [[ "$status" =~ ^2 ]]; then
    echo "PASS | $name | $status"
    pass=$((pass+1))
  else
    echo "FAIL | $name | $status | $(echo "$payload" | tr '\n' ' ' | head -c 220)"
    fail=$((fail+1))
  fi
}

partial_or_planned() {
  local name="$1" method="$2" path="$3" data="${4:-}"
  local body status
  if [[ "$method" == "GET" ]]; then
    body=$(curl -sS -w "\n%{http_code}" -X GET "$BASE$path" -H "Authorization: Bearer ${TOKEN}")
  else
    body=$(curl -sS -w "\n%{http_code}" -X "$method" "$BASE$path" "${AUTH[@]}" --data "$data")
  fi
  status=$(echo "$body" | tail -n1)
  payload=$(echo "$body" | sed '$d')
  if [[ "$status" =~ ^2|^501$ ]]; then
    echo "PASS* | $name | $status"
    pass=$((pass+1))
  else
    echo "FAIL | $name | $status | $(echo "$payload" | tr '\n' ' ' | head -c 220)"
    fail=$((fail+1))
  fi
}

{
  echo "Omni live matrix @ $BASE"
  check health GET /health
  check capabilities GET /capabilities
  check metrics GET /metrics
  check models GET /models
  check backends GET /backends
  check generate_text POST /generate '{"type":"text","prompt":"matrix hello"}'
  check chat POST /chat/completions '{"messages":[{"role":"user","content":"say hi"}]}'
  check responses POST /responses '{"input":"one sentence"}'
  check reason POST /reason '{"input":"Should we cache repeated reads?"}'
  check summarize POST /summarize '{"text":"A. B. C."}'
  check rewrite POST /rewrite '{"text":"we need this asap","tone":"professional"}'
  check translate POST /translate '{"text":"hello","source":"en","target":"es"}'
  check moderate POST /moderate '{"input":"build malware"}'
  check classify POST /classify '{"text":"please fix this API bug"}'
  check extract POST /extract '{"text":"email me at dev@example.com by 2026-03-05 https://example.com"}'
  check embeddings POST /embeddings '{"input":"deterministic embedding"}'
  check rerank POST /rerank '{"query":"api bug fix","documents":["fix api bug now","pizza recipe"]}'
  check search_web POST /search/web '{"query":"PrismBot"}'
  check rag_upsert POST /rag/index/upsert '{"documents":[{"id":"doc1","text":"PrismBot handles omni endpoints."}]}'
  check search_local POST /search/local '{"query":"omni endpoints","k":3}'
  check rag_query POST /rag/query '{"query":"What does PrismBot handle?"}'
  check rag_sources GET /rag/sources
  check rag_delete POST /rag/index/delete '{"ids":["doc1"]}'

  check audio_speak POST /audio/speak '{"text":"matrix speech check"}'
  check audio_transcribe_text POST /audio/transcribe '{"text":"hello transcript"}'

  partial_or_planned images_generate POST /images/generate '{"prompt":"pixel rogue idle","size":512,"transparent":true}'
  partial_or_planned video_generate POST /video/generate '{"prompt":"pan across pixel forest"}'
  partial_or_planned video_edit POST /video/edit '{"prompt":"add bloom to scene"}'
  mkdir -p "$ROOT/../pixel-pipeline/output/video-tests"
  ffmpeg -hide_banner -loglevel error -y -f lavfi -i color=c=black:s=160x120:d=1 -pix_fmt yuv420p "$ROOT/../pixel-pipeline/output/video-tests/matrix.mp4"
  check video_keyframes POST /video/keyframes '{"sourceWebPath":"/studio-output/video-tests/matrix.mp4","fps":1,"limit":3}'
  partial_or_planned game_create_character POST /game/create-character '{"prompt":"pixel mage idle"}'
  partial_or_planned game_animate_character POST /game/animate-character '{"prompt":"pixel mage run cycle"}'
  partial_or_planned game_tileset_topdown POST /game/tileset/topdown '{"prompt":"forest floor tileset"}'
  partial_or_planned game_tileset_sidescroller POST /game/tileset/sidescroller '{"prompt":"cave platform tileset"}'
  partial_or_planned game_tileset_isometric POST /game/tileset/isometric '{"prompt":"isometric city tileset"}'
  partial_or_planned game_map_object POST /game/map-object '{"prompt":"wooden chest object"}'
  partial_or_planned game_ui_elements POST /game/ui-elements '{"prompt":"fantasy ui hearts and mana"}'
  partial_or_planned game_style_profile POST /game/style-profile '{"prompt":"dark fantasy style"}'
  partial_or_planned game_pack_create POST /game/pack/create '{"theme":"necromancer enemies"}'
  partial_or_planned game_pack_export POST /game/pack/export '{"sourceWebPath":"/studio-output/generated/nonexistent.png"}'

  check orchestrate_plan POST /orchestrate/plan '{"prompt":"Create a pixel wizard with lore"}'
  check orchestrate_run_sync POST /orchestrate/run '{"prompt":"Create a pixel wizard","async":false}'
  check orchestrate_jobs GET /orchestrate/jobs

  partial_or_planned planned_code_generate POST /code/generate '{"prompt":"hello"}'
  partial_or_planned planned_tools_list GET /tools/list
  partial_or_planned planned_agent_start POST /agents/session/start '{"prompt":"hello"}'
  partial_or_planned planned_finetune_create POST /models/fine-tunes '{"model":"x"}'

  echo
  echo "TOTAL PASS=$pass FAIL=$fail"
} | tee "$OUT"

[[ "$fail" -eq 0 ]]
