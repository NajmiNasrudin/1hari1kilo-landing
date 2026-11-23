#!/bin/bash
# READ-ONLY: semak kenapa pembeli 1hari1 yang dah bayar tak dapat emel (Spacemail).
# Tiada apa diubah di server dan tiada rahsia dicetak.
#
#   bash tools/semak-emel.sh <CHIP purchase ID> [tarikh, contoh 03/Nov/2026]
set -uo pipefail
ID="${1:-}"
DAY="${2:-$(LC_ALL=C date +%d/%b/%Y)}"
[[ "$ID" =~ ^[a-f0-9-]{36}$ ]] || { echo "Perlukan Purchase ID CHIP yang sah."; exit 1; }
SSH_OPTS=(-o ControlMaster=auto -o ControlPath="$HOME/.ssh/cm-coachcem-%r@%h:%p" -o ControlPersist=120 -o ConnectTimeout=20 -o ServerAliveInterval=15)

ssh "${SSH_OPTS[@]}" coachcem ID="$ID" DAY="$DAY" bash -s <<'REMOTE'
cd ~/coachcem.com/1hari1 || exit 1
PHP=(/opt/alt/php84/usr/bin/php -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so)
STORE=/home/zjoawlrttx/coachcem-store/current

echo "== 1. Pembelian ditanda 'emel dihantar'?"
grep -q "$ID" sent-purchases.php 2>/dev/null && echo "YA (Spacemail terima emel tu)" || echo "TIDAK"

echo; echo "== 2. chip-callback.php guna Spacemail?"
grep -c "coachcem_legacy_mail\|sendDeliveryEmail" chip-callback.php | sed 's/^/rujukan Spacemail: /'
grep -q "sendBrevoEmail" chip-callback.php && echo "AMARAN: masih ada sendBrevoEmail (patch hilang?)"
"${PHP[@]}" -l chip-callback.php 2>&1 | tail -1

echo; echo "== 3. CHIP panggil chip-callback.php pada $DAY (masa, kaedah, status)"
for f in ~/access-logs/coachcem.com* ~/logs/coachcem.com*; do
  [ -f "$f" ] || continue
  case "$f" in *.gz) zgrep -h "chip-callback" "$f";; *) grep -h "chip-callback" "$f";; esac
done 2>/dev/null | grep "$DAY" | awk '{print $4, $6, $9}' | tail -20
echo "(200 = emel OK, 502 = emel gagal & CHIP akan cuba lagi)"

echo; echo "== 4. Ralat PHP berkaitan emel / pembelian ni"
for f in error_log ../error_log; do
  [ -f "$f" ] && grep -h -i "$ID\|spacemail\|email not sent\|chip-callback" "$f" | tail -15
done

echo; echo "== 5. Log hantar semula"
tail -5 ~/logs/1hari1-resend.log 2>/dev/null || echo "(tiada)"

echo; echo "== 6. Setting mail coachcem-store"
cd "$STORE" && "${PHP[@]}" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("mail.default"), " ", config("mail.mailers.smtp.host"), ":", config("mail.mailers.smtp.port"), " from=", config("mail.from.address"), PHP_EOL;' 2>&1 | tail -1
grep -q "function coachcem_legacy_mail" deploy/spaceship/legacy-bridge.php && echo "legacy-bridge: OK" || echo "legacy-bridge: TIADA coachcem_legacy_mail"

echo; echo "== 7. Ralat mail terkini dalam log Laravel"
grep -h -i "mail\|smtp\|transport" storage/logs/laravel*.log 2>/dev/null | grep -v -i "password\|secret" | tail -8 | cut -c1-300

echo; echo "== 8. Cuba ambil pembelian dari CHIP (dry-run, emel TIDAK dihantar)"
[ -f ~/bin/1hari1-resend-email.php ] && "${PHP[@]}" ~/bin/1hari1-resend-email.php "$ID" --dry-run 2>&1 || echo "(skrip resend belum ada di ~/bin)"
REMOTE
