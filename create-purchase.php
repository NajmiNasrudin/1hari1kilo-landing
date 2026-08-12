<?php
/**
 * Creates a Chip Collect purchase and returns the checkout_url to redirect
 * the customer to. The Chip secret key never reaches the browser -- it is
 * only ever used in this server-side request.
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed.'));
    exit;
}

$configPath = __DIR__ . '/chip-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(array('error' => 'Payment gateway belum dikonfigurasi. Sila cuba lagi sebentar, atau hubungi kami di WhatsApp.'));
    exit;
}
$config = require $configPath;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = array();
}

$fullName = isset($input['full_name']) ? trim($input['full_name']) : '';
$email    = isset($input['email']) ? trim($input['email']) : '';
$phone    = isset($input['phone']) ? trim($input['phone']) : '';
$bump     = !empty($input['video_bump']);

if ($fullName === '' || $email === '' || $phone === '') {
    http_response_code(422);
    echo json_encode(array('error' => 'Sila lengkapkan nama, emel dan nombor telefon.'));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array('error' => 'Alamat emel tidak sah.'));
    exit;
}

$products = array(
    array('name' => 'Cheat Code 1 Hari 1KG (E-book PDF)', 'price' => 4700),
);
if ($bump) {
    $products[] = array('name' => 'Video Ebook Camna Nak Bakar Lemak Kau!', 'price' => 7700);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);

$referencePrefix = $bump ? '1hari1-bundle-' : '1hari1-';

$payload = array(
    'brand_id' => $config['brand_id'],
    'reference' => $referencePrefix . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
    'client' => array(
        'full_name' => $fullName,
        'email'     => $email,
        'phone'     => $phone,
    ),
    'purchase' => array(
        'currency' => 'MYR',
        'products' => $products,
    ),
    'success_redirect' => $origin . '/terima-kasih.html',
    'failure_redirect' => $origin . '/checkout.html',
    'success_callback' => $origin . '/chip-callback.php',
);

$ch = curl_init('https://gate.chip-in.asia/api/v1/purchases/');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['secret_key'],
    ),
    CURLOPT_TIMEOUT => 20,
));
$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(array('error' => 'Tidak dapat menghubungi gateway pembayaran. Sila cuba lagi. (' . $curlError . ')'));
    exit;
}

$result = json_decode($response, true);

if ($statusCode >= 200 && $statusCode < 300 && !empty($result['checkout_url'])) {
    echo json_encode(array('checkout_url' => $result['checkout_url']));
    exit;
}

http_response_code(502);
$message = isset($result['message']) ? $result['message'] : 'Ralat tidak diketahui daripada gateway pembayaran.';
echo json_encode(array('error' => $message));
