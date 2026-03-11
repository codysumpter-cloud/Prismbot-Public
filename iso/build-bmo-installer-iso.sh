#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  build-bmo-installer-iso.sh \
    --ubuntu-iso <path-to-ubuntu.iso> \
    --output <path-to-output.iso> \
    [--hostname bmo-node] \
    [--username bmo] \
    [--password 'ChangeMeNow!'] \
    [--ollama-models 'omni-core:phase3,llama3.2:1b'] \
    [--with-gaming]

Notes:
- --with-gaming installs legal gaming tooling (Steam/RetroArch/mGBA), but no ROMs.
- Use Ubuntu Desktop ISO when enabling gaming mode.
EOF
}

UBUNTU_ISO=""
OUTPUT_ISO=""
HOSTNAME="bmo-node"
USERNAME="bmo"
PASSWORD="ChangeMeNow!"
OLLAMA_MODELS="omni-core:phase3,llama3.2:1b"
WITH_GAMING="0"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ubuntu-iso) UBUNTU_ISO="$2"; shift 2 ;;
    --output) OUTPUT_ISO="$2"; shift 2 ;;
    --hostname) HOSTNAME="$2"; shift 2 ;;
    --username) USERNAME="$2"; shift 2 ;;
    --password) PASSWORD="$2"; shift 2 ;;
    --ollama-models) OLLAMA_MODELS="$2"; shift 2 ;;
    --with-gaming) WITH_GAMING="1"; shift 1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1"; usage; exit 1 ;;
  esac
done

[[ -n "$UBUNTU_ISO" && -n "$OUTPUT_ISO" ]] || { usage; exit 1; }
[[ -f "$UBUNTU_ISO" ]] || { echo "Ubuntu ISO not found: $UBUNTU_ISO"; exit 1; }

for cmd in xorriso 7z openssl sed awk; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing dependency: $cmd"; exit 1; }
done

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT
EXTRACT_DIR="$WORKDIR/extract"
mkdir -p "$EXTRACT_DIR"

echo "[1/7] Extracting Ubuntu ISO"
7z x -y -o"$EXTRACT_DIR" "$UBUNTU_ISO" >/dev/null
chmod -R u+w "$EXTRACT_DIR"

echo "[2/7] Creating NoCloud autoinstall seed"
mkdir -p "$EXTRACT_DIR/nocloud"
PASSWORD_HASH="$(openssl passwd -6 "$PASSWORD")"

MODEL_PULL_BLOCK=""
IFS=',' read -ra _MODELS <<< "$OLLAMA_MODELS"
for model in "${_MODELS[@]}"; do
  model_trimmed="$(echo "$model" | awk '{$1=$1;print}')"
  [[ -n "$model_trimmed" ]] || continue
  MODEL_PULL_BLOCK+="    - curtin in-target --target=/target -- sudo -u ${USERNAME} bash -lc 'ollama pull ${model_trimmed} || true'\n"
done

GAMING_BLOCK=""
if [[ "$WITH_GAMING" == "1" ]]; then
  GAMING_BLOCK=$(cat <<EOF
    - curtin in-target --target=/target -- bash -lc 'apt-get update && apt-get install -y software-properties-common || true'
    - curtin in-target --target=/target -- bash -lc 'add-apt-repository -y multiverse || true'
    - curtin in-target --target=/target -- bash -lc 'apt-get update && apt-get install -y steam-installer retroarch mgba-qt || true'
    - curtin in-target --target=/target -- bash -lc 'mkdir -p /home/${USERNAME}/Games/ROMs-legal && chown -R ${USERNAME}:${USERNAME} /home/${USERNAME}/Games'
EOF
)
fi

cat > "$EXTRACT_DIR/nocloud/user-data" <<EOF
#cloud-config
autoinstall:
  version: 1
  locale: en_US.UTF-8
  keyboard:
    layout: us
  identity:
    hostname: ${HOSTNAME}
    username: ${USERNAME}
    password: '${PASSWORD_HASH}'
  ssh:
    install-server: true
    allow-pw: true
  packages:
    - git
    - curl
    - jq
  late-commands:
    - curtin in-target --target=/target -- bash -lc 'mkdir -p /home/${USERNAME}/.openclaw && chown -R ${USERNAME}:${USERNAME} /home/${USERNAME}/.openclaw'
    - curtin in-target --target=/target -- sudo -u ${USERNAME} bash -lc 'git clone https://github.com/codysumpter-cloud/PrismBot.git /home/${USERNAME}/.openclaw/workspace || true'
    - curtin in-target --target=/target -- sudo -u ${USERNAME} bash -lc 'cd /home/${USERNAME}/.openclaw/workspace && bash scripts/install-oneclick.sh || true'
    - curtin in-target --target=/target -- sudo -u ${USERNAME} bash -lc 'cd /home/${USERNAME}/.openclaw/workspace && bash scripts/update-all.sh || true'
    - curtin in-target --target=/target -- bash -lc 'curl -fsSL https://ollama.com/install.sh | sh || true'
EOF

printf "%b" "$MODEL_PULL_BLOCK" >> "$EXTRACT_DIR/nocloud/user-data"
printf "%b" "$GAMING_BLOCK" >> "$EXTRACT_DIR/nocloud/user-data"
cat >> "$EXTRACT_DIR/nocloud/user-data" <<'EOF'
  user-data:
    disable_root: false
EOF

touch "$EXTRACT_DIR/nocloud/meta-data"

echo "[3/7] Patching boot configs for autoinstall"
patch_grub_file() {
  local f="$1"
  [[ -f "$f" ]] || return 0
  if grep -q "autoinstall ds=nocloud" "$f"; then
    return 0
  fi
  sed -i -E 's@(linux[^\n]*---)@\1 autoinstall ds=nocloud\\;s=/cdrom/nocloud/@g' "$f"
}

patch_grub_file "$EXTRACT_DIR/boot/grub/grub.cfg"
patch_grub_file "$EXTRACT_DIR/boot/grub/loopback.cfg"
patch_grub_file "$EXTRACT_DIR/EFI/BOOT/grub.cfg"

if [[ -f "$EXTRACT_DIR/isolinux/txt.cfg" ]]; then
  sed -i -E 's@(append[^\n]*)@\1 autoinstall ds=nocloud;s=/cdrom/nocloud/@g' "$EXTRACT_DIR/isolinux/txt.cfg"
fi

echo "[4/7] Rebuilding md5sum.txt"
if [[ -f "$EXTRACT_DIR/md5sum.txt" ]]; then
  (cd "$EXTRACT_DIR" && find . -type f ! -name md5sum.txt -print0 | xargs -0 md5sum > md5sum.txt)
fi

echo "[5/7] Repacking ISO"
mkdir -p "$(dirname "$OUTPUT_ISO")"

xorriso \
  -indev "$UBUNTU_ISO" \
  -outdev "$OUTPUT_ISO" \
  -map "$EXTRACT_DIR" / \
  -boot_image any replay \
  -compliance no_emul_toc \
  >/dev/null

echo "[6/7] Basic validation"
if [[ ! -s "$OUTPUT_ISO" ]]; then
  echo "Output ISO is empty: $OUTPUT_ISO"
  exit 1
fi

echo "[7/7] Done"
echo "Created: $OUTPUT_ISO"
echo "Next: test in VM first before flashing real hardware."
