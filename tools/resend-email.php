<?php
/**
 * Resend the 1hari1 delivery email for one paid CHIP purchase.
 *
 * CLI only. Lives outside the web root on the server (~/bin/1hari1-resend-email.php)
 * and reuses the live 1hari1 config: chip-config.php, brevo-config.php and the
 * download links in chip-callback.php, so the email always carries the current links.
 *
 *   php 1hari1-resend-email.php <purchase-id> [--dry-run] [--to=other@email.com]
 *
 * The purchase is fetched from CHIP and must be paid, from our brand, with a 1hari1-
 * reference. Bundle vs e-book only follows the same rule as chip-callback.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = getenv('HARI1_DIR') ?: (getenv('HOME') . '/coachcem.com/1hari1');
$chipApi = rtrim(getenv('CHIP_API_BASE') ?: 'https://gate.chip-in.asia/api/v1', '/');
$brevoApi = getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/smtp/email';
$logFile = getenv('RESEND_LOG') ?: (getenv('HOME') . '/logs/1hari1-resend.log');
$groupLink = 'https://chat.whatsapp.com/I7wCMU3Aing1Ui4tI9Dgn2';
$videoBumpPrice = 7700;

function fail($message, $code = 1) {
    fwrite(STDERR, "GAGAL: $message\n");
    exit($code);
}

function http($method, $url, $headers, $body = null) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method);
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return array($status, $response === false ? '' : $response, $error);
    }
    $context = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : $body, 'timeout' => 20, 'ignore_errors' => true)));
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (isset($http_response_header) ? $http_response_header : array() as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return array($status, $response === false ? '' : $response, $response === false ? 'request failed' : '');
}

// ---- Arguments ----
$purchaseId = null;
$dryRun = false;
$to = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($arg, '--to=') === 0) {
        $to = trim(substr($arg, 5));
    } elseif ($purchaseId === null) {
        $purchaseId = trim($arg);
    } else {
        fail("argumen tak dikenali: $arg");
    }
}
if ($purchaseId === null || !preg_match('/^[a-f0-9-]{36}$/i', $purchaseId)) {
    fail('Perlukan Purchase ID CHIP yang sah (contoh: bd98cf3b-e0a9-4982-b84a-cdc1b0a6a86f).');
}
if ($to !== null && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fail('Alamat emel --to tidak sah.');
}

// ---- Live config ----
foreach (array('chip-config.php', 'brevo-config.php', 'chip-callback.php') as $file) {
    if (!is_file("$dir/$file")) {
        fail("$file tiada dalam $dir");
    }
}
$chip = require "$dir/chip-config.php";
$brevo = require "$dir/brevo-config.php";
if (empty($chip['secret_key']) || empty($chip['brand_id']) || empty($brevo['api_key']) || empty($brevo['sender_email'])) {
    fail('chip-config.php atau brevo-config.php tak lengkap.');
}
$callback = file_get_contents("$dir/chip-callback.php");
$links = array();
foreach (array('EBOOK_LINK', 'VIDEO_BUMP_LINK', 'WHATSAPP_LINK') as $name) {
    if (!preg_match('/\$' . $name . '\s*=\s*\'([^\']+)\'/', $callback, $m)) {
        fail("tak jumpa \$$name dalam chip-callback.php");
    }
    $links[$name] = $m[1];
}

// ---- Purchase from CHIP ----
list($status, $response, $error) = http('GET', "$chipApi/purchases/$purchaseId/", array('Authorization: Bearer ' . $chip['secret_key'], 'Accept: application/json'));
$purchase = json_decode($response, true);
if ($status !== 200 || !is_array($purchase)) {
    fail("tak dapat ambil pembelian dari CHIP (HTTP $status $error)");
}
$reference = isset($purchase['reference']) ? (string) $purchase['reference'] : '';
$state = isset($purchase['status']) ? (string) $purchase['status'] : '';
if (($purchase['id'] ?? '') !== $purchaseId || ($purchase['brand_id'] ?? '') !== $chip['brand_id']) {
    fail('pembelian ni bukan dari brand kita.');
}
if (strpos($reference, '1hari1-') !== 0) {
    fail("reference '$reference' bukan pembelian 1hari1.");
}
if (!in_array($state, array('paid', 'cleared', 'settled'), true)) {
    fail("status pembelian '$state', bukan paid. Emel tidak dihantar.");
}
$fullName = trim((string) ($purchase['client']['full_name'] ?? ''));
$email = $to !== null ? $to : trim((string) ($purchase['client']['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('emel pembeli dalam CHIP tidak sah. Guna --to=emel@betul.com');
}
$hasVideo = strpos($reference, 'bundle') !== false;
foreach ((array) ($purchase['purchase']['products'] ?? array()) as $product) {
    if ((int) ($product['price'] ?? 0) === $videoBumpPrice) {
        $hasVideo = true;
    }
}

// ---- Email (same as chip-callback.php, plus the buyer support group) ----
$subject = 'E-book Cheat Code 1 Hari 1KG — Muat Turun Acik Dah Sedia';
$button = function ($href, $label, $dark) {
    $style = $dark ? 'background:#0B0B0C;color:#FFD200;' : 'background:#FFD200;color:#0B0B0C;';
    return '<p style="margin:0 0 16px"><a href="' . htmlspecialchars($href) . '" style="display:inline-block;' . $style . 'font-weight:700;text-decoration:none;padding:14px 24px;border-radius:6px;">' . $label . '</a></p>';
};
$linksHtml = $button($links['EBOOK_LINK'], 'Muat Turun E-book (PDF)', false);
if ($hasVideo) {
    $linksHtml .= $button($links['VIDEO_BUMP_LINK'], 'Tonton Video Add-On', true);
}
$body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#0B0B0C;">
  <div style="background:#0B0B0C;padding:24px;border-radius:8px 8px 0 0;">
    <p style="color:#FFD200;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;margin:0">Cheat Code 1 Hari 1KG</p>
  </div>
  <div style="padding:24px;background:#EDEFF0;border-radius:0 0 8px 8px;">
    <p style="margin:0 0 16px;font-size:16px;">Terima kasih, ' . htmlspecialchars($fullName !== '' ? $fullName : 'acik') . '!</p>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Pembelian acik dah berjaya. Klik butang di bawah untuk muat turun terus:</p>
    ' . $linksHtml . '
    <p style="margin:20px 0 8px;font-size:15px;line-height:1.6;">Masuk grup support pembeli untuk tanya soalan dan dapat bimbingan:</p>
    ' . $button($groupLink, 'Join Grup WhatsApp', true) . '
    <div style="background:#FDF3D0;border-radius:8px;padding:16px 20px;margin:20px 0 0;text-align:center;">
      <p style="margin:0;font-size:13px;color:#0B0B0C;line-height:1.6;">Saya amat berharap agar acik2 menghormati ilmu yang terkandung dalam eBook ini dengan tidak berkongsi kandungan atau fail tersebut dengan orang lain tau.</p>
    </div>
    <p style="margin:20px 0 0;font-size:13px;color:#5A6570;line-height:1.6;">Simpan pautan ni baik-baik. Kalau ada masalah muat turun, hubungi kami di <a href="' . htmlspecialchars($links['WHATSAPP_LINK']) . '" style="color:#0B0B0C;">WhatsApp</a>.</p>
  </div>
</div>';

echo "Pembelian : $reference ($state)\n";
echo "Hantar ke : $email\n";
echo "Kandungan : e-book" . ($hasVideo ? ' + video' : ' sahaja') . " + grup WhatsApp\n";
if ($dryRun) {
    echo "DRY-RUN: emel TIDAK dihantar.\n";
    exit(0);
}

// ---- Send through Brevo ----
$payload = json_encode(array(
    'sender' => array('email' => $brevo['sender_email'], 'name' => $brevo['sender_name'] ?? 'Coach Cem'),
    'to' => array(array('email' => $email, 'name' => $fullName !== '' ? $fullName : $email)),
    'replyTo' => array('email' => $brevo['sender_email']),
    'subject' => $subject,
    'htmlContent' => $body,
));
list($status, $response, $error) = http('POST', $brevoApi, array('api-key: ' . $brevo['api_key'], 'Content-Type: application/json', 'Accept: application/json'), $payload);
$result = json_decode($response, true);

@mkdir(dirname($logFile), 0700, true);
@file_put_contents($logFile, date('c') . " $purchaseId $reference brevo=$status\n", FILE_APPEND | LOCK_EX);

if ($status !== 201) {
    $why = is_array($result) && isset($result['message']) ? $result['message'] : ($error !== '' ? $error : 'tiada butiran');
    fail("Brevo tolak emel (HTTP $status): $why");
}

// Mark as sent so a later CHIP retry does not email the buyer twice.
$dedupe = "$dir/sent-purchases.php";
$sent = is_file($dedupe) ? (require $dedupe) : array();
if (!is_array($sent)) {
    $sent = array();
}
if (!isset($sent[$purchaseId])) {
    $sent[$purchaseId] = time();
    file_put_contents($dedupe, "<?php\nreturn " . var_export($sent, true) . ";\n", LOCK_EX);
}

echo 'EMEL-DIHANTAR (Brevo messageId: ' . (is_array($result) && isset($result['messageId']) ? $result['messageId'] : '-') . ")\n";
