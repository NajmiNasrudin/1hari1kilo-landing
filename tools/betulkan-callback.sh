#!/bin/bash
# Betulkan chip-callback.php di server: semua emel melalui Spacemail (tanpa Brevo),
# dan emel pembeli yang gagal tak senyap: dicatat, tak ditanda "dah hantar", CHIP cuba semula.
# Perlu coachcem-store dengan mail:send-legacy dah dipasang (tools/tukar-ke-spacemail.sh).
# Backup dulu, semak sintaks PHP, tunjuk beza. Fail asal kekal kalau ada apa-apa masalah.
set -euo pipefail
# One shared SSH connection for every step (the server limits rapid new connections).
SSH_OPTS=(-o ControlMaster=auto -o ControlPath="$HOME/.ssh/cm-coachcem-%r@%h:%p" -o ControlPersist=120 -o ConnectTimeout=20 -o ServerAliveInterval=15)

HERE="$(cd "$(dirname "$0")" && pwd)"
ssh "${SSH_OPTS[@]}" coachcem 'mkdir -p ~/bin && chmod 700 ~/bin'
scp -q "${SSH_OPTS[@]}" "$HERE/patch-callback.php" coachcem:bin/1hari1-patch-callback.php

ssh "${SSH_OPTS[@]}" coachcem bash -s <<'REMOTE'
set -uo pipefail
PHP=/opt/alt/php84/usr/bin/php; [ -x "$PHP" ] || PHP=php
FILE=~/coachcem.com/1hari1/chip-callback.php
BRIDGE=/home/zjoawlrttx/coachcem-store/current/deploy/spaceship/legacy-bridge.php
if ! grep -q 'function coachcem_legacy_mail' "$BRIDGE" 2>/dev/null; then
  echo "STOP: Spacemail (mail:send-legacy) belum dipasang dalam coachcem-store. Jalankan tools/tukar-ke-spacemail.sh dulu. Tiada apa diubah."; exit 1
fi
BACKUP=~/backups/1hari1-callback-$(date +%Y%m%d-%H%M%S)
TMP=$(mktemp)

"$PHP" ~/bin/1hari1-patch-callback.php "$FILE" > "$TMP"
rc=$?
if [ $rc -eq 3 ]; then rm -f "$TMP"; echo "CALLBACK-OK (dah dibetulkan sebelum ni)"; exit 0; fi
if [ $rc -ne 0 ]; then rm -f "$TMP"; echo "Tiada apa diubah."; exit 1; fi
if ! "$PHP" -l "$TMP" >/dev/null 2>&1; then rm -f "$TMP"; echo "STOP: hasil pembetulan gagal semakan PHP. Tiada apa diubah."; exit 1; fi

mkdir -p "$BACKUP"
cp -p "$FILE" "$BACKUP/chip-callback.php"
echo "--- Beza (lama -> baru):"
diff -u "$BACKUP/chip-callback.php" "$TMP" || true
cat "$TMP" > "$FILE"   # keep the original owner and permissions
rm -f "$TMP"
if "$PHP" -l "$FILE" >/dev/null 2>&1; then
  echo "Backup: $BACKUP/chip-callback.php"
  echo "CALLBACK-OK"
else
  cp -p "$BACKUP/chip-callback.php" "$FILE"
  echo "STOP: semakan akhir gagal, fail asal dipulihkan."; exit 1
fi
REMOTE
