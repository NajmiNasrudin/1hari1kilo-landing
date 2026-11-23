#!/bin/bash
# Hantar semula emel akses (link muat turun) untuk pesanan kedai coachcem-store (reference COCEM-... / BBKM-...).
# Pesanan mesti dah paid. --to= untuk hantar ke emel yang betul bila pembeli salah taip emel masa checkout.
# Rekod pesanan dalam database tidak diubah.
#
#   bash tools/hantar-semula-kedai.sh COCEM-CHEATCODE-000276 [--to=emel@betul.com]
set -euo pipefail
NUMBER="${1:-}"
TO=""
for a in "${@:2}"; do
  case "$a" in
    --to=*) TO="${a#--to=}" ;;
    *) echo "Argumen tak dikenali: $a"; exit 1 ;;
  esac
done
[[ "$NUMBER" =~ ^[A-Z]+-[A-Z0-9-]+$ ]] || { echo "Perlukan nombor pesanan, contoh COCEM-CHEATCODE-000276"; exit 1; }
[ -z "$TO" ] || [[ "$TO" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Emel --to tidak sah."; exit 1; }

ssh -o ConnectTimeout=20 coachcem NUMBER="$NUMBER" TO="$TO" bash -s <<'REMOTE'
set -euo pipefail
cd /home/zjoawlrttx/coachcem-store/current
/opt/alt/php84/usr/bin/php -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$order = App\Models\Order::query()->where("number", getenv("NUMBER"))->first();
if (!$order) { fwrite(STDERR, "GAGAL: pesanan " . getenv("NUMBER") . " tiada dalam kedai.\n"); exit(1); }
if ($order->payment_status !== "paid") { fwrite(STDERR, "GAGAL: pesanan ni status $order->payment_status, bukan paid.\n"); exit(1); }
$to = getenv("TO") ?: $order->customer_email;
echo "Pesanan  : $order->number ($order->payment_status)\n";
echo "Pembeli  : $order->customer_name <$order->customer_email>\n";
echo "Barang   : " . $order->items()->pluck("product_name")->implode(", ") . "\n";
echo "Hantar ke: $to\n";
Illuminate\Support\Facades\Mail::to($to, $order->customer_name)->send(new App\Mail\OrderAccessMail($order));
echo "EMEL-DIHANTAR (Spacemail)\n";
'
REMOTE
