<?php
require_once 'db_connect.php'; // musi ustawiać $pdo (PDO)

// Parametry wejściowe
$month = $_GET['month'] ?? null; // oczekujemy formatu YYYY-MM
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 1;
$tzParam = $_GET['tz'] ?? 'UTC'; // opcjonalnie: 'Europe/Warsaw'

// Walidacja formatu miesiąca
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Niepoprawny format parametru month. Oczekiwano YYYY-MM.']);
    exit;
}

// Walidacja strefy (proste) - jeżeli nieprawidłowa, fallback do UTC
try {
    $tz = new DateTimeZone($tzParam);
} catch (Exception $e) {
    $tz = new DateTimeZone('UTC');
}

try {
    // Zakres dat: od pierwszego dnia miesiąca 00:00:00 do pierwszego dnia kolejnego miesiąca 00:00:00 (w DB zakładamy UTC lub zgodnie z kolumną)
    $startDt = new DateTime($month . '-01 00:00:00', new DateTimeZone('UTC'));
    $endDt = (clone $startDt)->modify('+1 month');

    $start = $startDt->format('Y-m-d H:i:s');
    $end = $endDt->format('Y-m-d H:i:s');

    // Zapytanie: LEFT JOIN z agregacją rezerwacji
    $sql = "
    SELECT 
        cs.id AS slot_id,
        cs.start_ts,
        cs.end_ts,
        IFNULL(r.booked_count, 0) AS booked_count,
        cs.capacity
    FROM calendar_slots cs
    LEFT JOIN (
        SELECT slot_id, COUNT(*) AS booked_count
        FROM reservations
        WHERE status IN ('pending','paid')
        GROUP BY slot_id
    ) r ON r.slot_id = cs.id
    WHERE cs.is_active = 1
      AND cs.location_id = ?
      AND cs.start_ts >= ?
      AND cs.start_ts < ?
    ORDER BY cs.start_ts
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$locationId, $start, $end]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($slots as $slot) {
        // start_ts w DB: zakładamy 'YYYY-MM-DD HH:MM:SS' w UTC lub w tej samej strefie,
        // konwertujemy do żądanej strefy tylko przy tworzeniu pola hour dla UI.
        $startTs = $slot['start_ts']; // string
        $dt = new DateTime($startTs, new DateTimeZone('UTC'));
        $dt->setTimezone($tz); // do lokalnej strefy użytkownika

        $date = $dt->format('Y-m-d'); // data w tz
        $hour = $dt->format('H:i');    // godzina w tz

        $capacity = isset($slot['capacity']) ? (int)$slot['capacity'] : 0;
        $booked = (int)$slot['booked_count'];
        $available = $capacity > 0 ? max(0, $capacity - $booked) : null; // null = brak limitu
        $isFull = $capacity > 0 ? ($booked >= $capacity) : false;

        if (!isset($result[$date])) {
            $result[$date] = [];
        }

        $result[$date][$hour] = [
            'slot_id' => (int)$slot['slot_id'],
            'is_full' => $isFull,
            'booked_count' => $booked,
            'capacity' => $capacity,
            'available_spots' => $available
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // W produkcji lepiej logować szczegóły błędu do pliku, a klientowi zwracać ogólny komunikat.
    echo json_encode(['error' => 'Błąd bazy danych'], JSON_UNESCAPED_UNICODE);
    // error_log($e->getMessage());
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nieoczekiwany błąd'], JSON_UNESCAPED_UNICODE);
    // error_log($e->getMessage());
    exit;
}
?>
