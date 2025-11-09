<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (isset($_GET['date']) && (!isset($_GET['from']) || !isset($_GET['to']))) {
    $date = trim($_GET['date']);
    if ($date !== '') {
        $_GET['from'] = $date;
        $_GET['to']   = $date;
    }
}

$DEV = true;

if ($DEV) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

try {
    $db_connect_path = __DIR__ . '\includes\db_connect.php';
    if (!file_exists($db_connect_path)) {
        throw new Exception('Brak pliku includes/db_connect.php w oczekiwanej lokalizacji.');
    }
    require_once $db_connect_path;

    if (!function_exists('db_connect')) {
        throw new Exception('Funkcja db_connect() nie została znaleziona w includes/db_connect.php.');
    }

    $db = db_connect();
    if (!$db) {
        throw new Exception('Nie udało się połączyć z bazą danych.');
    }

    if (method_exists($db, 'set_charset')) {
        $db->set_charset('utf8mb4');
    }

    $start = null;
    $end = null;
    if (!empty($_GET['from']) || !empty($_GET['to'])) {
        $start = isset($_GET['from']) ? trim($_GET['from']) : null;
        $end = isset($_GET['to'])   ? trim($_GET['to'])   : null;
    } else {
        $start = isset($_GET['start']) ? trim($_GET['start']) : null;
        $end = isset($_GET['end'])   ? trim($_GET['end'])   : null;
    }

    $useDateFilter = false;
    if ($start !== null && $end !== null && $start !== '' && $end !== '') {
        $d1 = DateTime::createFromFormat('Y-m-d', $start);
        $d2 = DateTime::createFromFormat('Y-m-d', $end);
        $validDates = $d1 && $d1->format('Y-m-d') === $start && $d2 && $d2->format('Y-m-d') === $end;
        if (!$validDates) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Parametry daty powinny mieć format YYYY-MM-DD (np. 2025-11-01).']);
            exit;
        }
        if ($d1 > $d2) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Data początkowa nie może być późniejsza niż końcowa.']);
            exit;
        }
        $useDateFilter = true;
    }

    $sql = "
        SELECT 
            cs.id AS slot_id,
            cs.date,
            cs.time,
            cs.capacity,
            COUNT(r.id) AS reserved_count
        FROM calendar_slots cs
        LEFT JOIN reservations r 
            ON r.slot_id = cs.id
            AND r.status IN ('pending', 'paid')
    ";
    if ($useDateFilter) {
        $sql .= " WHERE cs.date BETWEEN ? AND ? ";
    }
    $sql .= " GROUP BY cs.id ORDER BY cs.date, cs.time ";

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        $err = method_exists($db, 'error') ? $db->error : 'Błąd przygotowania zapytania';
        throw new Exception('Błąd przygotowania zapytania SQL: ' . $err);
    }

    if ($useDateFilter) {
        $stmt->bind_param('ss', $start, $end);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error ?: 'Błąd wykonania zapytania';
        throw new Exception('Błąd wykonania zapytania SQL: ' . $err);
    }

    $result = $stmt->get_result();
    if ($result === false) {
        throw new Exception('Błąd pobierania wyników zapytania.');
    }

    $slots = [];
    while ($row = $result->fetch_assoc()) {
        $reserved_count = isset($row['reserved_count']) ? intval($row['reserved_count']) : 0;
        $capacity = isset($row['capacity']) ? intval($row['capacity']) : 0;
        $is_full = ($capacity > 0) ? ($reserved_count >= $capacity) : false;

        $slots[] = [
            'id' => $row['slot_id'],
            'date' => $row['date'],
            'time' => $row['time'],
            'capacity' => $capacity,
            'reserved_count' => $reserved_count,
            'available' => !$is_full
        ];
    }

    if (!empty($_GET['debug'])) {
        echo json_encode([
            'ok' => true,
            'count' => count($slots),
            'slots' => $slots,
            'debug_info' => [
                'db_host' => getenv('DB_HOST') ?: 'unknown',
                'db_name' => getenv('DB_NAME') ?: 'unknown',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok' => true,
            'count' => count($slots),
            'slots' => $slots
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    $stmt->close();

} catch (Exception $e) {
    if (function_exists('error_log')) {
        error_log('get-availability.php error: ' . $e->getMessage());
    }
    http_response_code(500);
    if ($DEV) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Wystąpił błąd serwera.']);
    }
    exit;
}
