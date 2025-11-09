<?php
define('P24_SIGNATURE_KEY', getenv('P24_SIGNATURE_KEY'));
define('P24_SECRET_KEY', getenv('P24_SECRET_KEY'));
define('P24_GATEWAY_IP', getenv('P24_GATEWAY_IP'));

function verifySignature($data, $secretKey) {
    return strtoupper(md5($data . $secretKey));
}

function verifyIP($ip) {
    $allowedIps = [P24_GATEWAY_IP];
    return in_array($ip, $allowedIps);
}

if (!verifyIP($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    echo "Unauthorized IP address";
    exit;
}

$postData = file_get_contents('php://input');
$data = [];
parse_str($postData, $data);

$transactionId = $data['p24_session_id'] ?? null;
$paymentStatus = $data['p24_status'] ?? null;
$crc = $data['p24_crc'] ?? null;
$receivedSign = $data['p24_sign'] ?? $data['sign'] ?? null;

if (empty($transactionId) || empty($paymentStatus) || empty($crc)) {
    http_response_code(400);
    echo "Missing parameters";
    exit;
}

$computed = strtoupper(md5($postData . P24_SECRET_KEY));
if (!hash_equals($computed, strtoupper((string)$receivedSign))) {
    http_response_code(400);
    error_log("P24 signature mismatch for order: " . ($data['p24_order_id'] ?? 'unknown'));
    echo "Invalid signature";
    exit;
}

include_once __DIR__ . '\includes\db_connect.php';

if ($paymentStatus == 'completed') {
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Database connection failed";
        exit;
    }

    $stmt = $conn->prepare("UPDATE bookings SET payment_status = ?, booking_status = ? WHERE transaction_id = ?");
    $bookingStatus = 'confirmed';
    $stmt->bind_param('sss', $paymentStatus, $bookingStatus, $transactionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    http_response_code(200);
    echo "Payment successful, status updated";
} else {
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Database connection failed";
        exit;
    }

    $failed = 'failed';
    $stmt = $conn->prepare("UPDATE bookings SET payment_status = ? WHERE transaction_id = ?");
    $stmt->bind_param('ss', $failed, $transactionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    http_response_code(200);
    echo "Payment failed, status updated";
}
?>