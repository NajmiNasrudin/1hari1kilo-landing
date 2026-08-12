<?php
/**
 * Proxy for the "sistem fnb page paid" company-level Chip webhook.
 * Chip fires that webhook for every purchase.paid event across the whole
 * account (all products share one brand_id, so Chip can't filter by
 * product at the webhook level). This proxy swallows 1hari1-* purchases
 * -- already handled by their own dedicated success_callback -- and
 * forwards everything else unchanged to the real F&B Toolkit endpoint,
 * so that product's email delivery keeps working exactly as before.
 */

header('Content-Type: application/json');

function respond($code, $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('error' => 'Method not allowed.'));
}

$configPath = __DIR__ . '/chip-config.php';
if (!file_exists($configPath)) {
    respond(500, array('error' => 'Not configured.'));
}
$config = require $configPath;

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

$reference = isset($purchase['reference']) ? $purchase['reference'] : '';

// 1hari1 purchases already have their own dedicated success_callback --
// swallow this one so the customer doesn't get a second, wrong email.
if (strpos($reference, '1hari1-') === 0) {
    respond(200, array('ok' => true, 'skipped' => '1hari1 purchase, handled separately'));
}

// Forward everything else, unchanged (same body + signature), to the
// real F&B Toolkit endpoint so its own signature check still passes.
$FNB_ENDPOINT = 'https://bizbuddyhq.com/sistem-fnb-salespage/chip-webhook.php';

$fwd = curl_init($FNB_ENDPOINT);
curl_setopt_array($fwd, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'X-Signature: ' . $signatureHeader,
    ),
    CURLOPT_TIMEOUT => 20,
));
curl_exec($fwd);
$forwardStatus = curl_getinfo($fwd, CURLINFO_HTTP_CODE);
curl_close($fwd);

respond(200, array('ok' => true, 'forwarded' => true, 'downstream_status' => $forwardStatus));
