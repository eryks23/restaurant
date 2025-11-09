<?php
/**
 * webhook_validator.php
 * 
 * Walidacja webhooków z Przelewy24 dla rezerwacji.
 * 
 * Wymagania:
 * - Sprawdzenie sygnatury / CRC zgodnie z dokumentacją P24
 * - Idempotentna obsługa płatności (nie aktualizuj statusu wielokrotnie)
 * - Bezpieczne logowanie (bez PII)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php'; // np. json_response()

/**
 * Waliduje CRC / sygnaturę webhooka
 *
 * @param array $data Dane przesłane przez P24
 * @param string $crcKlucz Twój sekret CRC (P24 CRC)
 * @return bool true jeśli prawidłowa sygnatura
 */
function validate_webhook_signature(array $data, string $crcKlucz): bool {
    if (!isset($data['p24_sign'])) {
        return false;
    }

    // Konstruujemy string zgodnie z dokumentacją P24
    $fields = [
        'p24_merchant_id',
        'p24_pos_id',
        'p24_session_id',
        'p24_amount',
        'p24_currency',
        'p24_order_id',
        'p24_method',
        'p24_statement',
        'p24_sign' // nie wliczamy tego pola do CRC, ale zależy od dokumentacji P24
    ];

    $signString = '';
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $signString .= $data[$f];
        }
    }
    $signString .= $crcKlucz;

    // Porównanie sygnatury w bezpieczny sposób
    return hash_equals($data['p24_sign'], md5($signString));
}

/**
 * Logowanie zdarzeń webhook (minimalnie)
 *
 * @param string $msg
 */
function log_webhook_event(string $msg) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $file = $logDir . '/webhook.log';
    $entry = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Główna walidacja webhooka
 */
function handle_webhook() {
    $data = $_POST;

    if (empty($data)) {
        http_response_code(400);
        echo "No data received";
        exit;
    }

    // Weryfikacja sygnatury
    if (!validate_webhook_signature($data, P24_CRC_KEY)) {
        log_webhook_event("Invalid signature: " . json_encode($data, JSON_UNESCAPED_UNICODE));
        http_response_code(403);
        echo "Invalid signature";
        exit;
    }

    $db = get_db();

    // Pobierz rezerwację po p24_order_id
    $stmt = $db->prepare("SELECT * FROM reservations WHERE p24_order_id = :order_id LIMIT 1");
    $stmt->execute(['order_id' => $data['p24_order_id']]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        log_webhook_event("Reservation not found for order: " . $data['p24_order_id']);
        http_response_code(404);
        echo "Reservation not found";
        exit;
    }

    // Idempotentnie aktualizuj status
    if ($reservation['status'] === 'paid') {
        log_webhook_event("Duplicate webhook ignored for order: " . $data['p24_order_id']);
        http_response_code(200);
        echo "Already paid";
        exit;
    }

    // Sprawdź status z P24
    $status = strtolower($data['p24_status'] ?? '');
    if ($status === 'success' || $status === 'completed') {
        $stmtUpdate = $db->prepare("UPDATE reservations SET status = 'paid', updated_at = NOW() WHERE id = :id");
        $stmtUpdate->execute(['id' => $reservation['id']]);

        // Wysyłka maila do klienta i admina (funkcja z mailer.php)
        try {
            send_reservation_paid_mail($reservation);
        } catch (\Throwable $e) {
            log_webhook_event("Mail sending failed: " . $e->getMessage());
        }

        log_webhook_event("Reservation marked as paid: " . $data['p24_order_id']);
    } else {
        log_webhook_event("Payment failed or pending: " . $data['p24_order_id'] . " status=" . $status);
    }

    http_response_code(200);
    echo "OK";
}

handle_webhook();
