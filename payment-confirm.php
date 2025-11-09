<?php
// payment-confirm.php
declare(strict_types=1);
ini_set('display_errors', '0'); // na produkcji trzymać 0
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');

// Ścieżka do logu (upewnij się, że plik jest zapisywalny przez proces PHP)
$logFile = __DIR__ . '/payment-confirm.log';

/* ---------- Helpers ---------- */
function log_msg(string $msg): void {
    global $logFile;
    $time = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
}

function req_param(string $name): ?string {
    if (isset($_REQUEST[$name])) {
        $val = trim((string)$_REQUEST[$name]);
        return $val === '' ? null : $val;
    }
    return null;
}

function normalize_status(string $s): string {
    $s = mb_strtolower(trim($s));
    $mapSuccess = ['success', 'true', 'paid', 'completed', 'ok', '1'];
    return in_array($s, $mapSuccess, true) ? 'success' : $s;
}

function wants_json(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strpos($accept, 'application/json') !== false || (isset($_GET['format']) && $_GET['format'] === 'json');
}

/**
 * Placeholder: wykonaj weryfikację płatności u bramki (server-to-server).
 * Zwraca true jeżeli bramka potwierdza płatność. 
 * Implementuj zgodnie z dokumentacją operatora (p24/PayU/itd).
 */
function verify_with_gateway(string $bookingId, array $paymentRecord, ?string $p24_status_param): bool {
    // UWAGA: tutaj należy wykonać rzeczywiste wywołanie do API bramki, sprawdzić signature, amount, currency, status.
    // Na potrzeby tego przykładu zwracamy false (brak automatycznej weryfikacji).
    // Jeśli masz dostęp do API bramki — zaimplementuj tutaj wywołanie HTTP z weryfikacją.
    return false;
}

/* ---------- Użyj db_connect.php zamiast tworzyć nowe połączenie tutaj ---------- */
require_once __DIR__ . '/db_connect.php'; // powinien utworzyć $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    log_msg("ERROR: db_connect.php nie ustawił \$pdo (PDO).");
    http_response_code(500);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'internal error'], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<h1>Błąd serwera</h1><p>Problem z konfiguracją.</p>";
    }
    exit;
}

/* ---------- Pobierz parametry ---------- */
$bookingIdParam = req_param('booking_id');
$tokenParam     = req_param('token');
$p24StatusParam = req_param('p24_status');

$logBooking = $bookingIdParam ?? 'NULL';
$logTokenShort = $tokenParam ? substr($tokenParam, 0, 4) . '...' : 'NULL';
$logP24 = $p24StatusParam ?? 'NULL';
log_msg("RECV: booking_id={$logBooking}, token={$logTokenShort}, p24_status={$logP24}");

// Walidacja minimalna
if ($bookingIdParam === null || $tokenParam === null) {
    $msg = 'Brak wymaganych parametrów (booking_id i token są wymagane).';
    log_msg("ERROR: $msg");
    http_response_code(400);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<h1>Błąd</h1><p>$msg</p>";
    }
    exit;
}

// cast jeśli potrzebujesz int: $bookingId = (int)$bookingIdParam;
$bookingId = $bookingIdParam;
$p24StatusNormalized = $p24StatusParam !== null ? normalize_status($p24StatusParam) : null;

try {
    // Pobierz payment powiązany z booking_id
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE booking_id = :booking_id LIMIT 1');
    $stmt->execute([':booking_id' => $bookingId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $msg = "No payments record for booking_id={$bookingId}";
        log_msg("ERROR: $msg");
        http_response_code(404);
        if (wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        } else {
            echo "<h1>Nie znaleziono płatności</h1><p>$msg</p>";
        }
        exit;
    }

    // Weryfikacja tokena - używamy hash_equals (bezpiecznego porównania)
    $storedToken = (string)($payment['token'] ?? '');
    if (!hash_equals($storedToken, $tokenParam)) {
        $msg = "Token mismatch for booking_id={$bookingId}";
        log_msg("SECURITY: $msg (provided first4={$logTokenShort})");
        http_response_code(403);
        if (wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token nieprawidłowy'], JSON_UNESCAPED_UNICODE);
        } else {
            echo "<h1>Dostęp zabroniony</h1><p>Token nieprawidłowy.</p>";
        }
        exit;
    }

    // Sprawdź status w DB
    $dbStatus = mb_strtolower((string)($payment['status'] ?? 'unknown'));
    $successStates = ['paid', 'completed', 'success'];

    if (in_array($dbStatus, $successStates, true)) {
        $msg = "Payment already confirmed in DB (status={$dbStatus}) for booking_id={$bookingId}";
        log_msg("INFO: $msg");
        if (wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'payment already confirmed', 'status' => $dbStatus], JSON_UNESCAPED_UNICODE);
        } else {
            echo "<h1>Dziękujemy — płatność potwierdzona</h1><p>$msg</p>";
        }
        exit;
    }

    // Jeśli p24_status wskazuje success — rozważ aktualizację, ale najlepiej -> verify_with_gateway
    $shouldTreatAsPaid = false;
    if ($p24StatusNormalized === 'success') {
        // Jeżeli wolisz bezpośrednio ufać p24_status (niezalecane) -> uncomment:
        // $shouldTreatAsPaid = true;

        // Zalecane: wykonaj server-to-server weryfikację u bramki (sprawdź amount, currency, status, podpisy)
        if (verify_with_gateway($bookingId, $payment, $p24StatusParam)) {
            $shouldTreatAsPaid = true;
            log_msg("INFO: Gateway verification succeeded for booking_id={$bookingId}");
        } else {
            // jeśli verify nie zaimplementowane, możesz logować i nie aktualizować automatycznie
            log_msg("INFO: Gateway verification failed/disabled for booking_id={$bookingId}; p24_status={$p24StatusParam}");
        }
    }

    if ($shouldTreatAsPaid) {
        // Update DB na paid w transakcji
        $pdo->beginTransaction();
        try {
            $updateSql = 'UPDATE payments
                          SET status = :new_status,
                              p24_status = :p24_status,
                              paid_at = NOW(),
                              updated_at = NOW()
                          WHERE id = :id';
            $uStmt = $pdo->prepare($updateSql);
            $uStmt->execute([
                ':new_status' => 'paid',
                ':p24_status' => $p24StatusParam,
                ':id'         => $payment['id'],
            ]);

            // Opcjonalnie: aktualizacja powiązanego bookingu/rezerwacji (np. status = paid)
            // $bStmt = $pdo->prepare('UPDATE bookings SET status = :s WHERE id = :id');
            // $bStmt->execute([':s' => 'paid', ':id' => $bookingId]);

            $pdo->commit();
            $msg = "Updated payment -> paid for booking_id={$bookingId} (payment_id={$payment['id']})";
            log_msg("INFO: $msg");

            if (wants_json()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'payment confirmed and updated', 'status' => 'paid'], JSON_UNESCAPED_UNICODE);
            } else {
                // Możesz tu zamiast echo robić redirect do strony "thank you"
                echo "<h1>Płatność potwierdzona</h1><p>$msg</p>";
            }
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = "DB update error for booking_id={$bookingId}: " . $e->getMessage();
            log_msg("ERROR: $err");
            http_response_code(500);
            if (wants_json()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'db update failed'], JSON_UNESCAPED_UNICODE);
            } else {
                echo "<h1>Błąd</h1><p>Nie udało się zaktualizować statusu płatności.</p>";
            }
            exit;
        }
    }

    // Nie potwierdzono płatności
    $msg = "Payment not confirmed in DB (status={$dbStatus}) and no gateway success confirmation for booking_id={$bookingId}.";
    log_msg("INFO: $msg");
    http_response_code(200);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'payment not confirmed', 'db_status' => $dbStatus, 'p24_status' => $p24StatusParam], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<h1>Płatność niepotwierdzona</h1><p>$msg</p>";
    }
    exit;

} catch (PDOException $ex) {
    $err = "DB connection/query error: " . $ex->getMessage();
    log_msg("ERROR: $err");
    http_response_code(500);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'database error'], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<h1>Błąd serwera</h1><p>Problem z bazą danych.</p>";
    }
    exit;
} catch (Throwable $e) {
    $err = "Unexpected error: " . $e->getMessage();
    log_msg("ERROR: $err");
    http_response_code(500);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'internal error'], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<h1>Błąd</h1><p>Wystąpił nieoczekiwany błąd.</p>";
    }
    exit;
}
