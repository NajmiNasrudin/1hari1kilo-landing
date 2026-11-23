#!/bin/bash
# Baiki worker coachcem-store yang gagal sejak 20 Nov 2026 ("Class PDO not found"):
# PHP CLI server tak lagi muat PDO sendiri, jadi setiap arahan perlu -d extension=pdo.so.
#   1. Tambah pdo.so dalam cron coachcem-store (worker emel + marketing). Backup crontab dulu.
#   2. Tambah pdo.so dalam legacy-bridge.php (emel, CAPI dan notifikasi jualan 1hari1). Backup dulu.
#   3. Jalankan worker emel & marketing sekali sekarang supaya emel yang tersangkut terus keluar.
#   4. Hantar semula emel 1hari1 yang gagal sejak 20 Nov (dari error_log), kecuali yang dah dihantar.
#
#   bash tools/baiki-pdo.sh
set -euo pipefail

ssh -o ConnectTimeout=20 coachcem bash -s <<'REMOTE'
set -euo pipefail
TS=$(date +%Y%m%d-%H%M%S)
BK=~/backups/pdo-fix-$TS
mkdir -p "$BK"
STORE=/home/zjoawlrttx/coachcem-store/current
BIN=/opt/alt/php84/usr/bin/php
PHP=("$BIN" -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so)

echo "== 0/4 Semak pdo.so boleh dimuat"
"${PHP[@]}" -r 'exit(class_exists("PDO") && in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);' 2>/dev/null \
  || { echo "STOP: pdo.so tak dapat dimuat. Tiada apa diubah."; exit 1; }
echo "OK"

echo; echo "== 1/4 Cron"
crontab -l > "$BK/crontab.txt"
perl -pe 'if (m{coachcem-store/current} && m{extension=pdo_sqlite\.so} && !m{extension=pdo\.so }) { s{-d extension=pdo_sqlite\.so}{-d extension=pdo.so -d extension=pdo_sqlite.so}g }' "$BK/crontab.txt" > "$BK/crontab.new"
if cmp -s "$BK/crontab.txt" "$BK/crontab.new"; then
  echo "Cron dah ada pdo.so."
else
  crontab "$BK/crontab.new"
  echo "Cron dikemas kini ($(diff "$BK/crontab.txt" "$BK/crontab.new" | grep -c '^>') baris). Backup: $BK/crontab.txt"
fi

echo; echo "== 2/4 legacy-bridge.php"
BRIDGE=$STORE/deploy/spaceship/legacy-bridge.php
if grep -q "'extension=pdo.so'" "$BRIDGE"; then
  echo "Bridge dah ada pdo.so."
else
  cp -p "$BRIDGE" "$BK/legacy-bridge.php"
  perl -pi -e "s{'-d', 'extension=pdo_sqlite\.so'}{'-d', 'extension=pdo.so', '-d', 'extension=pdo_sqlite.so'}g" "$BRIDGE"
  if ! "$BIN" -l "$BRIDGE" >/dev/null; then
    cp -p "$BK/legacy-bridge.php" "$BRIDGE"; echo "STOP: ralat sintaks, bridge dipulihkan."; exit 1
  fi
  echo "Bridge dikemas kini ($(grep -c "'extension=pdo.so'" "$BRIDGE") tempat). Backup: $BK/legacy-bridge.php"
fi

echo; echo "== 3/4 Hantar emel & event yang tersangkut"
cd "$STORE"
BEFORE=$("${PHP[@]}" -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::table("jobs")->where("queue", "emails")->count();')
echo "Emel beratur sebelum: $BEFORE"
/bin/flock -n storage/framework/mail-worker.lock "${PHP[@]}" artisan queue:work database --queue=emails --stop-when-empty --max-time=240 --timeout=40 --tries=5 --no-interaction \
  && date -Iseconds > storage/app/private/mail-worker-last-success.txt || echo "(worker emel sedang jalan dari cron, biar dia habiskan)"
/bin/flock -n storage/framework/coachcem-marketing-worker.lock "${PHP[@]}" artisan queue:work database --queue=marketing --stop-when-empty --max-time=120 --timeout=30 --tries=6 --no-interaction --quiet \
  && date -Iseconds > storage/app/private/marketing-worker-last-success.txt || true
"${PHP[@]}" -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "Emel beratur selepas: ", DB::table("jobs")->where("queue", "emails")->count(), " | gagal sejak 20 Nov: ", DB::table("failed_jobs")->where("queue", "emails")->where("failed_at", ">=", "2026-11-20")->count(), PHP_EOL;'

echo; echo "== 4/4 Emel 1hari1 yang gagal sejak 20 Nov"
cd ~/coachcem.com/1hari1
IDS=$(grep -h "buyer email not sent for purchase" error_log ../error_log 2>/dev/null \
  | awk -F'[][]' '{print $2" "$0}' | grep -E '^(2[0-9]|3[01])-Nov-2026' \
  | grep -o 'purchase [a-f0-9-]\{36\}' | awk '{print $2}' | sort -u || true)
if [ -z "$IDS" ]; then echo "Tiada."; fi
for id in $IDS; do
  if grep -q "$id" sent-purchases.php 2>/dev/null; then echo "$id: dah dihantar (CHIP cuba semula berjaya)"; continue; fi
  "$BIN" ~/bin/1hari1-resend-email.php "$id" 2>&1 | tail -1 | sed "s/^/$id: /"
done
echo; echo "PDO-FIX-OK"
REMOTE
