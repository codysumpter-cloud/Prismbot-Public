#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${PRISMBOT_CORE_ENV:-$HOME/.config/prismbot-core.env}"
TARGET_MODEL="${OMNI_PHASE3_TARGET_MODEL:-omni-core:phase3}"

python3 - <<PY
from pathlib import Path
p=Path("$ENV_FILE")
text=p.read_text() if p.exists() else ''
updates={
 'OMNI_TEXT_MODEL_DEFAULT':'$TARGET_MODEL',
 'OMNI_TEXT_MODEL_QUALITY':'$TARGET_MODEL',
 'OMNI_TEXT_MODEL_FAST':'llama3.2:1b',
}
lines=text.splitlines(); out=[]; seen=set()
for ln in lines:
    if '=' in ln and not ln.lstrip().startswith('#'):
        k=ln.split('=',1)[0]
        if k in updates:
            out.append(f"{k}={updates[k]}"); seen.add(k); continue
    out.append(ln)
for k,v in updates.items():
    if k not in seen:
        out.append(f"{k}={v}")
p.parent.mkdir(parents=True, exist_ok=True)
p.write_text('\n'.join(out)+'\n')
print('updated', p)
PY

systemctl --user restart prismbot-core.service
sleep 1
systemctl --user --no-pager --full status prismbot-core.service | sed -n '1,40p'

echo "phase3 promoted: $TARGET_MODEL"
