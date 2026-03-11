#!/usr/bin/env bash
set -euo pipefail

REPO="${1:-codysumpter-cloud/PrismBot}"
JSON_FILE="PROJECTS/phase2b-issues.json"

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI not found"
  exit 1
fi

if [ ! -f "$JSON_FILE" ]; then
  echo "Missing $JSON_FILE"
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "gh is not authenticated. Run: gh auth login"
  exit 1
fi

echo "Ensuring labels exist..."
for lbl in phase-2b feature; do
  gh label create "$lbl" --repo "$REPO" --force >/dev/null 2>&1 || true
done

echo "Creating issues from $JSON_FILE on $REPO"
python3 - "$REPO" "$JSON_FILE" <<'PY'
import json, subprocess, sys
repo = sys.argv[1]
json_file = sys.argv[2]
with open(json_file, 'r', encoding='utf-8') as f:
    items = json.load(f)
for i, it in enumerate(items, 1):
    labels = ','.join(it.get('labels', []))
    cmd = [
        'gh','issue','create',
        '--repo', repo,
        '--title', it['title'],
        '--body', it['body']
    ]
    if labels:
        cmd += ['--label', labels]
    print(f"[{i}/{len(items)}] {it['title']}")
    subprocess.run(cmd, check=True)
print('Done.')
PY
