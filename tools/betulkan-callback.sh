#!/bin/bash
# Betulkan chip-callback.php di server supaya emel pembeli yang gagal tak senyap:
# log sebab Brevo gagal, jangan tanda "dah hantar", dan biar CHIP cuba semula.
# Backup dulu, semak sintaks PHP, tunjuk beza. Fail asal kekal kalau ada apa-apa masalah.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ssh coachcem 'mkdir -p ~/bin && chmod 700 ~/bin'
scp -q "$HERE/patch-callback.php" coachcem:bin/1hari1-patch-callback.php

ssh coachcem bash -s <<'REMOTE'
set -uo pipefail
PHP=/opt/alt/php84/usr/bin/php; [ -x "$PHP" ] || PHP=php
FILE=~/coachcem.com/1hari1/chip-callback.php
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
