#!/bin/bash
# READ-ONLY: semak status emel akses untuk pesanan kedai coachcem-store (COCEM-... / BBKM-...).
# Tunjuk pesanan, sama ada emel masih beratur / gagal dalam queue, dan sama ada queue worker berjalan.
#
#   bash tools/semak-kedai.sh COCEM-CHEATCODE-000499
set -euo pipefail
NUMBER="${1:-}"
[[ "$NUMBER" =~ ^[A-Z]+-[A-Z0-9-]+$ ]] || { echo "Perlukan nombor pesanan, contoh COCEM-CHEATCODE-000499"; exit 1; }

ssh -o ConnectTimeout=20 coachcem NUMBER="$NUMBER" bash -s <<'REMOTE'
set -uo pipefail
cd /home/zjoawlrttx/coachcem-store/current
/opt/alt/php84/usr/bin/php -d extension=dom.so -d extension=fileinfo.so -d extension=mbstring.so -d extension=pdo.so -d extension=pdo_sqlite.so -d extension=xmlwriter.so -d extension=phar.so -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$o = App\Models\Order::query()->where("number", getenv("NUMBER"))->first();
if (!$o) { echo "GAGAL: pesanan tiada dalam kedai.\n"; exit(1); }
echo "== Pesanan\n$o->number | $o->status / $o->payment_status | paid_at " . ($o->paid_at ?? "-") . "\n";
echo "Emel: $o->customer_email\n";
foreach ($o->items()->with("downloadLinks")->get() as $i) echo "Barang: $i->product_name | $i->fulfillment_status | link: " . $i->downloadLinks->count() . "\n";
$needle = "%i:" . $o->id . ";%";
echo "\n== Emel akses dalam queue (belum dihantar)\n";
$pending = DB::table("jobs")->where("payload", "like", "%OrderAccessMail%")->get(["id", "queue", "attempts", "created_at", "available_at", "payload"]);
$mine = $pending->filter(fn ($j) => str_contains($j->payload, "i:" . $o->id . ";"));
echo "Semua emel akses beratur: " . $pending->count() . " | untuk pesanan ni: " . $mine->count() . "\n";
foreach ($mine as $j) echo "  job $j->id cubaan $j->attempts, dicipta " . date("d M H:i", $j->created_at + 8*3600) . "\n";
$oldest = DB::table("jobs")->min("created_at");
echo "Job paling lama dalam queue: " . ($oldest ? date("d M H:i", $oldest + 8*3600) : "tiada") . " (kalau lama, worker tak jalan)\n";
echo "\n== Emel akses yang GAGAL\n";
$failed = DB::table("failed_jobs")->where("payload", "like", "%OrderAccessMail%")->where("payload", "like", $needle)->orderByDesc("failed_at")->limit(3)->get(["failed_at", "exception"]);
if ($failed->isEmpty()) echo "Tiada.\n";
foreach ($failed as $f) echo "  $f->failed_at: " . strtok($f->exception, "\n") . "\n";
echo "\n== Mailer: " . config("mail.default") . " " . config("mail.mailers.smtp.host") . "\n";
'
echo; echo "== Worker emel kedai"
echo "Kali terakhir berjaya: $(cat storage/app/private/mail-worker-last-success.txt 2>/dev/null || echo TIADA)"
echo "Log terkini:"; tail -n 6 storage/logs/mail-worker.log 2>/dev/null | cut -c1-220 | sed "s/^/  /"
echo; echo "== PHP: adakah PDO dimuat tanpa pdo.so?"
/opt/alt/php84/usr/bin/php -d extension=pdo_sqlite.so -r 'echo class_exists("PDO") ? "YA\n" : "TIDAK (worker cron akan gagal)\n";' 2>&1 | tail -1
REMOTE
