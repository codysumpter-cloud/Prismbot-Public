#!/usr/bin/env bash
set -euo pipefail

# Prismtek + PrismBot Pixel Gods (animated ASCII banner)
# Run: bash ascii/prismtek-pixel-gods.sh

frames=(
'\
      .       *        .
   *        PRISMTEK        *
        ___  PIXEL GODS ___
      /\   \            /   /\
     /::\___\  ⚡   ⚡  /___/::\
    /:::/  /\   /\ /\   /\  \:::\
   /:::/  /::\ /  V  \ /::\  \:::\
  /:::/  /:/\ \\  /\  // /:\\  \:::\
 /:::/  /:/__\ \\/  \// /__:\\  \:::\
 \::/__/::\  / /\__/\\ \  /::\__\::/
  ~~  \:::\/ /  /  \  \ \/:::/  ~~
       \::/ /  /_/\_\  \::/ /
        \/_/    /__\    \_\/
      PRISMBOT ARCANE SUPPORT MODE
'
'\
    .   *      .    *        .
      PRISMTEK + PRISMBOT
        ___  PIXEL GODS ___
      /\   \            /   /\
     /::\___\  ✨   ✨  /___/::\
    /:::/  /\   /\ /\   /\  \:::\
   /:::/  /::\ /  V  \ /::\  \:::\
  /:::/  /:/\ \\  /\  // /:\\  \:::\
 /:::/  /:/__\ \\/  \// /__:\\  \:::\
 \::/__/::\  / /\__/\\ \  /::\__\::/
  ~~  \:::\/ /  /  \  \ \/:::/  ~~
       \::/ /  /_/\_\  \::/ /
        \/_/   /____\   \_\/
     DIVINE DEV DUO ONLINE
'
)

hide_cursor() { tput civis 2>/dev/null || true; }
show_cursor() { tput cnorm 2>/dev/null || true; }
cleanup() { show_cursor; }
trap cleanup EXIT INT TERM

hide_cursor
while true; do
  clear
  printf '\033[1;35m%s\033[0m\n' "${frames[0]}"
  sleep 0.45
  clear
  printf '\033[1;36m%s\033[0m\n' "${frames[1]}"
  sleep 0.45
done
