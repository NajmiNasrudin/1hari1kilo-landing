#!/bin/bash
# Tukar emel 1hari1 dari Brevo ke Spacemail, ikut turutan selamat:
#   1. Pasang arahan mail:send-legacy dalam coachcem-store di server (3 fail).
#   2. Hantar satu emel ujian melalui Spacemail ke emel acik.
#   3. Betulkan chip-callback.php supaya guna Spacemail, tanpa Brevo.
# Setiap langkah backup dulu, semak dulu, dan berhenti tanpa ubah apa-apa kalau ada yang pelik.
#
#   bash tools/tukar-ke-spacemail.sh            (akan tanya emel untuk ujian)
set -euo pipefail
# One shared SSH connection for every step (the server limits rapid new connections).
SSH_OPTS=(-o ControlMaster=auto -o ControlPath="$HOME/.ssh/cm-coachcem-%r@%h:%p" -o ControlPersist=120 -o ConnectTimeout=20 -o ServerAliveInterval=15)

TEST_TO=""
for a in "$@"; do
  case "$a" in
    --test=*) TEST_TO="${a#--test=}"; [[ "$TEST_TO" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Emel --test tidak sah"; exit 1; } ;;
    *) echo "Argumen tak dikenali: $a"; exit 1 ;;
  esac
done

if [ -z "$TEST_TO" ]; then
  read -r -p "Emel acik untuk terima emel ujian (contoh nama@gmail.com): " TEST_TO
fi
[[ "$TEST_TO" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Emel ujian tidak sah."; exit 1; }
[[ "$TEST_TO" == *"@anda.com" ]] && { echo "Tu emel contoh. Masukkan emel acik sendiri."; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"
STORE="$HOME/Desktop/Claude/Cocahcem.com/coachcem-store"
BRANCH=origin/legacy-mail-spacemail
FILES="app/Mail/LegacyHtmlMail.php app/Console/Commands/SendLegacyMail.php deploy/spaceship/legacy-bridge.php"
TS=$(date +%Y%m%d-%H%M%S)

echo "== 1/3 Pasang mail:send-legacy dalam coachcem-store"
cd "$STORE"
git fetch -q origin
for f in $FILES; do
  git diff --quiet "$BRANCH" -- "$f" || { echo "STOP: $f dalam komputer lain dari GitHub ($BRANCH)."; exit 1; }
done
ssh "${SSH_OPTS[@]}" coachcem "mkdir -p ~/coachcem-mail-stage-$TS/app/Mail ~/coachcem-mail-stage-$TS/app/Console/Commands ~/coachcem-mail-stage-$TS/deploy/spaceship"
for f in $FILES; do scp -q "${SSH_OPTS[@]}" "$f" "coachcem:coachcem-mail-stage-$TS/$f"; done

ssh "${SSH_OPTS[@]}" coachcem TS="$TS" bash -s <<'REMOTE'
set -euo pipefail
PHP=(/opt/alt/php84/usr/bin/php -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so)
APP=$(readlink -f /home/zjoawlrttx/coachcem-store/current)
STAGE=~/coachcem-mail-stage-$TS
BACKUP=~/backups/coachcem-mail-$TS
cd "$APP"
if grep -q 'function coachcem_legacy_mail' deploy/spaceship/legacy-bridge.php && [ -f app/Console/Commands/SendLegacyMail.php ]; then
  echo "mail:send-legacy dah dipasang sebelum ni."
else
  echo "69b373efc5954d20e66213136d436483acdd3a6a0e7977b30a8bb7831dab56ce  deploy/spaceship/legacy-bridge.php" | sha256sum --check --quiet \
    || { echo "STOP: legacy-bridge.php di server lain dari versi dijangka. Tiada apa diubah."; exit 1; }
  for f in app/Mail/LegacyHtmlMail.php app/Console/Commands/SendLegacyMail.php; do
    test ! -e "$f" || { echo "STOP: $f dah wujud. Tiada apa diubah."; exit 1; }
  done
  for f in app/Mail/LegacyHtmlMail.php app/Console/Commands/SendLegacyMail.php deploy/spaceship/legacy-bridge.php; do
    "${PHP[@]}" -l "$STAGE/$f" >/dev/null || { echo "STOP: ralat sintaks $f. Tiada apa diubah."; exit 1; }
  done
  mkdir -p "$BACKUP/deploy/spaceship"
  cp -p deploy/spaceship/legacy-bridge.php "$BACKUP/deploy/spaceship/"
  cp "$STAGE/app/Mail/LegacyHtmlMail.php" app/Mail/LegacyHtmlMail.php
  cp "$STAGE/app/Console/Commands/SendLegacyMail.php" app/Console/Commands/SendLegacyMail.php
  cat "$STAGE/deploy/spaceship/legacy-bridge.php" > deploy/spaceship/legacy-bridge.php
  chmod 644 app/Mail/LegacyHtmlMail.php app/Console/Commands/SendLegacyMail.php
  if ! "${PHP[@]}" artisan list --raw 2>/dev/null | grep -q '^mail:send-legacy'; then
    cp -p "$BACKUP/deploy/spaceship/legacy-bridge.php" deploy/spaceship/legacy-bridge.php
    rm -f app/Mail/LegacyHtmlMail.php app/Console/Commands/SendLegacyMail.php
    echo "STOP: arahan mail:send-legacy tak muncul. Fail asal dipulihkan."; exit 1
  fi
  echo "Dipasang. Backup: $BACKUP"
fi
rm -rf "$STAGE"
MAILER=$("${PHP[@]}" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("mail.default"), " ", config("mail.mailers.smtp.host"), " ", config("mail.from.address");' 2>/dev/null || true)
echo "Mailer aktif: ${MAILER:-tak dapat dibaca}"
case "$MAILER" in
  smtp\ *spacemail*) ;;
  *) echo "STOP: coachcem-store belum guna Spacemail SMTP (mailer: ${MAILER:-?}). chip-callback.php TIDAK diubah."; exit 1 ;;
esac
REMOTE

if true; then
  echo "== 2/3 Emel ujian melalui Spacemail ke $TEST_TO"
  ssh "${SSH_OPTS[@]}" coachcem TEST_TO="$TEST_TO" bash -s <<'REMOTE'
set -uo pipefail
PHP=(/opt/alt/php84/usr/bin/php -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so)
cd /home/zjoawlrttx/coachcem-store/current
printf '{"to":"%s","name":"Ujian","subject":"Ujian Spacemail 1hari1","html":"<p>Ini emel ujian dari sistem 1hari1 melalui Spacemail. Kalau acik nampak emel ni, Spacemail jalan.</p>"}' "$TEST_TO" \
  | "${PHP[@]}" artisan mail:send-legacy --no-interaction \
  && echo "UJIAN-OK: semak inbox (dan Spam) $TEST_TO" \
  || { echo "STOP: Spacemail gagal hantar emel ujian. chip-callback.php TIDAK diubah."; exit 1; }
REMOTE
fi

echo "== 3/3 Betulkan chip-callback.php (Spacemail, tanpa Brevo)"
bash "$HERE/betulkan-callback.sh"
echo "SPACEMAIL-OK"
