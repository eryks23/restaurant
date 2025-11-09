<?php
loadEnvFile(__DIR__ . '/server/env.example');

function loadEnvFile($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!strpos($line, '=')) continue;
        list($name, $val) = explode('=', $line, 2);
        $name = trim($name);
        $val = trim($val);
        if (!getenv($name)) putenv("$name=$val");
    }
}

function env($key, $default = null) {
    $v = getenv($key);
    return $v === false ? $default : $v;
}

function sendJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function badRequest($msg = 'Bad request') {
    sendJson(['error' => $msg], 400);
}

function unauthorized($msg = 'Unauthorized') {
    sendJson(['error' => $msg], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    badRequest('Only POST is allowed');
}

$input = $_POST;
$raw = file_get_contents('php://input');
if ($raw) {
    $maybe = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($maybe)) {
        $input = array_merge($input, $maybe);
    }
}

$required = ['booking_id','token','amount','currency','return_url','notify_url'];
foreach ($required as $r) {
    if (empty($input[$r])) {
        badRequest("Missing $r");
    }
}

$bookingId = $input['booking_id'];
$token = $input['token'];
$amountRaw = $input['amount'];
$currency = $input['currency'];
$returnUrl = $input['return_url'];
$notifyUrl = $input['notify_url'];
$returnJson = isset($input['return_json']) && in_array(strtolower($input['return_json']), ['1','true','yes']);

if (!is_numeric($amountRaw)) {
    badRequest('Amount must be numeric');
}
$amount = (int) round(floatval($amountRaw) * 100);

$pdo = null;
$dbFile = __DIR__ . '/includes/db_connect.php';
if (file_exists($dbFile)) {
    require_once $dbFile;
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        $pdo = null;
    }
}

function verifyBookingAndToken($pdo, $bookingId, $token) {
    if (!$pdo) return false;
    $sql = "SELECT * FROM bookings WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) return false;
    if (isset($booking['token'])) {
        return hash_equals((string)$booking['token'], (string)$token) ? $booking : false;
    }
    return $token ? $booking : false;
}

$booking = verifyBookingAndToken($pdo, $bookingId, $token);
if (!$booking) {
    unauthorized('Invalid booking or token');
}

$merchantId = env('PRZELEWY24_MERCHANT_ID');
$posId = env('PRZELEWY24_POS_ID');
$crc = env('PRZELEWY24_CRC');
$apiKey = env('PRZELEWY24_API_KEY');
$registerUrl = env('PRZELEWY24_API_REGISTER_URL', 'https://sandbox.przelewy24.pl/api/v1/transaction/register');
$redirectTemplate = env('PRZELEWY24_REDIRECT_TEMPLATE', 'https://sandbox-go.przelewy24.pl/trnRequest/{TOKEN}');
$signMethod = env('SIGN_METHOD', 'json');

if (!$merchantId || !$crc || !$posId || !$apiKey) {
    sendJson(['error' => 'Payment gateway is not configured on server'], 500);
}

$sessionId = uniqid('sess_', true);

$transaction = [
    'merchantId' => (int)$merchantId,
    'posId' => (int)$posId,
    'sessionId' => $sessionId,
    'amount' => (int)$amount,
    'currency' => $currency,
    'description' => isset($input['description']) ? $input['description'] : "Booking #$bookingId",
    'email' => isset($booking['email']) ? $booking['email'] : null,
    'country' => isset($input['country']) ? $input['country'] : 'PL',
    'language' => isset($input['language']) ? $input['language'] : 'pl',
    'urlReturn' => $returnUrl,
    'urlStatus' => $notifyUrl,
];

function calculateSignature(array $transaction, $merchantId, $crc, $method = 'json') {
    $sessionId = $transaction['sessionId'];
    $amount = (int)$transaction['amount'];
    $currency = $transaction['currency'];
    if ($method === 'concat') {
        $str = $sessionId . '|' . $merchantId . '|' . $amount . '|' . $currency . '|' . $crc;
        return hash('sha384', $str);
    }
    $payload = [
        'sessionId' => $sessionId,
        'merchantId' => (int)$merchantId,
        'amount' => $amount,
        'currency' => $currency,
        'crc' => $crc,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha384', $json);
}

$sign = calculateSignature($transaction, $merchantId, $crc, $signMethod);
$transaction['sign'] = $sign;
$transaction = array_filter($transaction, function($v){ return $v !== null; });

$ch = curl_init($registerUrl);
$payload = json_encode($transaction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, $posId . ':' . $apiKey);

$responseRaw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    sendJson(['error' => 'Gateway request failed', 'detail' => $curlErr], 502);
}

$response = json_decode($responseRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendJson(['error' => 'Invalid response from gateway', 'raw' => $responseRaw], 502);
}

if ($httpCode !== 200 || !isset($response['data']) || empty($response['data']['token'])) {
    $err = isset($response['error']) ? $response['error'] : 'Registration failed';
    sendJson(['error' => $err, 'gateway' => $response], 502);
}

$tokenFromGateway = $response['data']['token'];
$redirectUrl = str_replace('{TOKEN}', urlencode($tokenFromGateway), $redirectTemplate);

$paymentRequestId = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare('INSERT INTO payment_requests (booking_id, session_id, p24_token, amount, currency, status, created_at) VALUES (:booking_id, :session_id, :p24_token, :amount, :currency, :status, NOW())');
        $stmt->execute([
            ':booking_id' => $bookingId,
            ':session_id' => $sessionId,
            ':p24_token' => $tokenFromGateway,
            ':amount' => $amount,
            ':currency' => $currency,
            ':status' => 'initiated'
        ]);
        $paymentRequestId = $pdo->lastInsertId();
    } catch (Exception $e) {
        $paymentRequestId = null;
    }
}

$result = [
    'redirect_url' => $redirectUrl,
    'gateway_token' => $tokenFromGateway,
    'payment_request_id' => $paymentRequestId,
    'status' => 'initiated',
];

if ($returnJson || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    sendJson($result, 200);
}

header('Location: ' . $redirectUrl, true, 302);
exit;
