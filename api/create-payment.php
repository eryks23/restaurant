<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']); exit; }

require_once __DIR__ . '\includes\db_connect.php';

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$amount_pln = isset($_POST['amount_pln']) ? (float)$_POST['amount_pln'] : 0.0;

if ($name === '' || $email === '' || $amount_pln <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid input']);
    exit;
}

$amount_grosze = (int) round($amount_pln * 100);

$mysqli->begin_transaction();

try {
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $stmt = $mysqli->prepare('INSERT INTO users (name, email, phone, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->bind_param('sss', $name, $email, $phone);
        $stmt->execute();
        $user_id = $stmt->insert_id;
        $stmt->close();
    }

    $stmt = $mysqli->prepare('INSERT INTO reservations (user_id, amount_pln, amount_grosze, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->bind_param('idd', $user_id, $amount_pln, $amount_grosze);
    $stmt->execute();
    $reservation_id = $stmt->insert_id;
    $stmt->close();

    $status = 'new';
    $stmt = $mysqli->prepare('INSERT INTO payments (reservation_id, amount_pln, amount_grosze, status, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->bind_param('idds', $reservation_id, $amount_pln, $amount_grosze, $status);
    $stmt->execute();
    $payment_id = $stmt->insert_id;
    $stmt->close();

    $mysqli->commit();

    $p24_fields_array = [
        'p24_session_id' => (string)$reservation_id,
        'p24_order_id' => (string)$payment_id,
        'p24_amount' => $amount_grosze,
        'p24_currency' => 'PLN',
        'p24_description' => "Rezerwacja #{$reservation_id}",
        'p24_email' => $email,
        'p24_client_name' => $name
    ];

    echo json_encode([
        'ok' => true,
        'booking_id' => $reservation_id,
        'amount_pln' => number_format($amount_pln, 2, '.', ''),
        'amount_grosze' => $amount_grosze,
        'p24_url' => 'https://sandbox.przelewy24.pl/trnRequest',
        'p24_fields' => $p24_fields_array
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error']);
    exit;
}
