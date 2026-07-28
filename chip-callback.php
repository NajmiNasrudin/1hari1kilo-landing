<?php
/**
 * Chip Collect success_callback endpoint. Chip calls this server-to-server
 * when a purchase is paid. Verifies the request is genuinely from Chip
 * (RSA signature check against Chip's public key), then emails the
 * customer their download link(s). Never trust this payload without the
 * signature check -- anyone could otherwise POST a fake "paid" event.
 */

header('Content-Type: application/json');

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$EBOOK_LINK = 'https://drive.google.com/file/d/1SHg7aTaEtEDEhYDRf5Xt0gip6yKd8NZO/view?usp=sharing';
$VIDEO_BUMP_LINK = 'https://drive.google.com/drive/folders/1kLX397w5l3nkt7sji22yTpg2Oq-P2Vtq';
$VIDEO_BUMP_PRICE = 7700;
$WHATSAPP_LINK = 'https://wa.me/60123456789';
$OWNER_NOTIFY_EMAIL = 'akucem7@me.com';

function respond($code, $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function sendSmtpEmail($smtpConfig, $toEmail, $toName, $subject, $htmlBody) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtpConfig['host'];
        $mail->Port       = $smtpConfig['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpConfig['username'];
        $mail->Password   = $smtpConfig['password'];
        $mail->SMTPSecure = $smtpConfig['secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($smtpConfig['from_email'], $smtpConfig['from_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('error' => 'Method not allowed.'));
}

$configPath = __DIR__ . '/chip-config.php';
if (!file_exists($configPath)) {
    respond(500, array('error' => 'Not configured.'));
}
$config = require $configPath;

$smtpConfigPath = __DIR__ . '/smtp-config.php';
if (!file_exists($smtpConfigPath)) {
    respond(500, array('error' => 'Email sender not configured.'));
}
$smtpConfig = require $smtpConfigPath;

$rawBody = file_get_contents('php://input');
$signatureHeader = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';

if ($rawBody === '' || $signatureHeader === '') {
    respond(400, array('error' => 'Missing body or signature.'));
}

// Fetch Chip's public key to verify this callback is genuinely from Chip.
$ch = curl_init('https://gate.chip-in.asia/api/v1/public_key/');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $config['secret_key']),
    CURLOPT_TIMEOUT => 15,
));
$publicKeyResponse = curl_exec($ch);
$publicKeyStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($publicKeyStatus < 200 || $publicKeyStatus >= 300) {
    respond(502, array('error' => 'Could not verify signer.'));
}
$publicKeyPem = json_decode($publicKeyResponse, true);
if (!is_string($publicKeyPem) || $publicKeyPem === '') {
    respond(502, array('error' => 'Invalid public key response.'));
}

$signature = base64_decode($signatureHeader, true);
if ($signature === false) {
    respond(400, array('error' => 'Malformed signature.'));
}

$verified = openssl_verify($rawBody, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
if ($verified !== 1) {
    respond(400, array('error' => 'Signature verification failed.'));
}

$purchase = json_decode($rawBody, true);
if (!is_array($purchase)) {
    respond(400, array('error' => 'Invalid payload.'));
}

$status = isset($purchase['status']) ? $purchase['status'] : '';
if ($status !== 'paid') {
    // Not a successful payment -- acknowledge and do nothing.
    respond(200, array('ok' => true, 'skipped' => 'status not paid'));
}

$purchaseId = isset($purchase['id']) ? $purchase['id'] : '';
$email = isset($purchase['client']['email']) ? $purchase['client']['email'] : '';
$fullName = isset($purchase['client']['full_name']) ? $purchase['client']['full_name'] : 'acik';

if ($purchaseId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, array('error' => 'Missing purchase id or valid client email.'));
}

// Dedupe -- webhooks can be retried by Chip, don't email twice for the same purchase.
$dedupeFile = __DIR__ . '/sent-purchases.php';
$sent = file_exists($dedupeFile) ? (require $dedupeFile) : array();
if (!is_array($sent)) {
    $sent = array();
}
if (isset($sent[$purchaseId])) {
    respond(200, array('ok' => true, 'skipped' => 'already sent'));
}

$hasVideoBump = false;
if (!empty($purchase['purchase']['products']) && is_array($purchase['purchase']['products'])) {
    foreach ($purchase['purchase']['products'] as $product) {
        if (isset($product['price']) && (int) $product['price'] === $VIDEO_BUMP_PRICE) {
            $hasVideoBump = true;
            break;
        }
    }
}

$subject = 'E-book Cheat Code 1 Hari 1KG — Muat Turun Acik Dah Sedia';

$linksHtml = '<p style="margin:0 0 16px"><a href="' . htmlspecialchars($EBOOK_LINK) . '" style="display:inline-block;background:#FFD200;color:#0B0B0C;font-weight:700;text-decoration:none;padding:14px 24px;border-radius:6px;">Muat Turun E-book (PDF)</a></p>';
if ($hasVideoBump) {
    $linksHtml .= '<p style="margin:0 0 16px"><a href="' . htmlspecialchars($VIDEO_BUMP_LINK) . '" style="display:inline-block;background:#0B0B0C;color:#FFD200;font-weight:700;text-decoration:none;padding:14px 24px;border-radius:6px;">Tonton Video Add-On</a></p>';
}

$body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#0B0B0C;">
  <div style="background:#0B0B0C;padding:24px;border-radius:8px 8px 0 0;">
    <p style="color:#FFD200;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;margin:0">Cheat Code 1 Hari 1KG</p>
  </div>
  <div style="padding:24px;background:#EDEFF0;border-radius:0 0 8px 8px;">
    <p style="margin:0 0 16px;font-size:16px;">Terima kasih, ' . htmlspecialchars($fullName) . '!</p>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Pembelian acik dah berjaya. Klik butang di bawah untuk muat turun terus:</p>
    ' . $linksHtml . '
    <p style="margin:20px 0 0;font-size:13px;color:#5A6570;line-height:1.6;">Simpan pautan ni baik-baik. Kalau ada masalah muat turun, hubungi kami di <a href="' . htmlspecialchars($WHATSAPP_LINK) . '" style="color:#0B0B0C;">WhatsApp</a>.</p>
  </div>
</div>';

$mailSent = sendSmtpEmail($smtpConfig, $email, $fullName, $subject, $body);

// Notify the owner too -- amount, buyer name, what they bought.
$totalCents = 0;
$productNames = array();
if (!empty($purchase['purchase']['products']) && is_array($purchase['purchase']['products'])) {
    foreach ($purchase['purchase']['products'] as $product) {
        if (isset($product['price'])) {
            $totalCents += (int) $product['price'];
        }
        if (isset($product['name'])) {
            $productNames[] = $product['name'];
        }
    }
}
$totalFormatted = 'RM' . number_format($totalCents / 100, 2);
$phone = isset($purchase['client']['phone']) ? $purchase['client']['phone'] : '-';
$reference = isset($purchase['reference']) ? $purchase['reference'] : '-';

$notifySubject = 'Pembelian Baru — ' . $fullName . ' — ' . $totalFormatted;
$notifyBody = '
<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;color:#0B0B0C;">
  <div style="background:#0B0B0C;padding:20px;border-radius:8px 8px 0 0;">
    <p style="color:#FFD200;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;margin:0">Pembelian Baru — Cheat Code 1 Hari 1KG</p>
  </div>
  <div style="padding:20px;background:#EDEFF0;border-radius:0 0 8px 8px;font-size:14px;line-height:1.7;">
    <p style="margin:0 0 8px"><strong>Nama:</strong> ' . htmlspecialchars($fullName) . '</p>
    <p style="margin:0 0 8px"><strong>Emel:</strong> ' . htmlspecialchars($email) . '</p>
    <p style="margin:0 0 8px"><strong>Telefon:</strong> ' . htmlspecialchars($phone) . '</p>
    <p style="margin:0 0 8px"><strong>Produk:</strong> ' . htmlspecialchars(implode(', ', $productNames)) . '</p>
    <p style="margin:0 0 8px"><strong>Jumlah:</strong> ' . htmlspecialchars($totalFormatted) . '</p>
    <p style="margin:0 0 8px"><strong>Reference:</strong> ' . htmlspecialchars($reference) . '</p>
    <p style="margin:0;color:#5A6570;font-size:12px;"><strong>Purchase ID:</strong> ' . htmlspecialchars($purchaseId) . '</p>
  </div>
</div>';

$notifySent = sendSmtpEmail($smtpConfig, $OWNER_NOTIFY_EMAIL, 'Coach Cem', $notifySubject, $notifyBody);

$sent[$purchaseId] = time();
file_put_contents($dedupeFile, "<?php\nreturn " . var_export($sent, true) . ";\n");

respond(200, array('ok' => true, 'mail_sent' => $mailSent, 'notify_sent' => $notifySent));
