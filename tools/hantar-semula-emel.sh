#!/bin/bash
# Hantar semula emel muat turun 1hari1 kepada seorang pembeli.
#
#   bash tools/hantar-semula-emel.sh <Purchase ID CHIP> [--dry-run] [--to=emel@lain.com]
#
# Purchase ID ada dalam CHIP dashboard → Payment Details → ID.
# --dry-run : tunjuk siapa & apa yang akan dihantar, tanpa hantar.
# --to=...  : hantar ke emel lain (contoh pembeli tersalah taip emel).
set -euo pipefail
# One shared SSH connection for every step (the server limits rapid new connections).
SSH_OPTS=(-o ControlMaster=auto -o ControlPath="$HOME/.ssh/cm-coachcem-%r@%h:%p" -o ControlPersist=120 -o ConnectTimeout=20 -o ServerAliveInterval=15)

ID="${1:-}"
if ! [[ "$ID" =~ ^[a-fA-F0-9-]{36}$ ]]; then
  echo "Guna: bash tools/hantar-semula-emel.sh <Purchase ID CHIP> [--dry-run] [--to=emel]"; exit 1
fi
shift
ARGS=("$ID")
for a in "$@"; do
  case "$a" in
    --dry-run) ARGS+=("$a") ;;
    --to=*) [[ "$a" =~ ^--to=[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Emel --to tidak sah"; exit 1; }; ARGS+=("$a") ;;
    *) echo "Argumen tak dikenali: $a"; exit 1 ;;
  esac
done

HERE="$(cd "$(dirname "$0")" && pwd)"
ssh "${SSH_OPTS[@]}" coachcem 'mkdir -p ~/bin && chmod 700 ~/bin'
scp -q "${SSH_OPTS[@]}" "$HERE/resend-email.php" coachcem:bin/1hari1-resend-email.php
ssh "${SSH_OPTS[@]}" coachcem "chmod 600 ~/bin/1hari1-resend-email.php; PHP=/opt/alt/php84/usr/bin/php; [ -x \"\$PHP\" ] || PHP=php; \"\$PHP\" ~/bin/1hari1-resend-email.php ${ARGS[*]}"
