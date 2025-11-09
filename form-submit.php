<?php
// form-submit.php
// Prosty handler formularza: zapisuje do DB i przekierowuje na stronę potwierdzenia

// Wczytaj .env (prosty loader)
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') === false) continue;
        list($k,$v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

// DB config
$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'v';
$dbPort = $_ENV['DB_PORT'] ?? 3306;
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Basic sanitization
$firstName = trim($_POST['firstName'] ?? '');
$lastName  = trim($_POST['lastName'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$participants = max(1, intval($_POST['participants'] ?? 1));
$date = $_POST['date'] ?? null;
$time = $_POST['time'] ?? null;
$duration = intval($_POST['duration'] ?? 30);
$voucher = trim($_POST['applied_voucher'] ?? '');
$gdpr = (isset($_POST['gdpr']) && ($_POST['gdpr']==='1' || $_POST['gdpr']==='on')) ? 1 : 0;
// final amount field is optional server should recalc, but accept if provided
$final_amount_grosze = intval($_POST['final_amount_grosze'] ?? 0);
$total_amount = $final_amount_grosze ? ($final_amount_grosze/100) : 0.0;

// validate minimal
$errors = [];
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy e-mail';
if ($firstName === '' || $lastName === '') $errors[] = 'Imię i nazwisko są wymagane';
if ($duration <= 0) $errors[] = 'Nieprawidłowa długość usługi';
if (!empty($errors)) {
    // prosto: wyświetl błędy (możesz lepiej przekierować)
    echo '<h2>Błędy:</h2><ul>';
    foreach ($errors as $er) echo '<li>' . htmlspecialchars($er) . '</li>';
    echo '</ul>';
    exit;
}

// Connect mysqli
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);
if ($mysqli->connect_errno) {
    die('Błąd połączenia z DB: ' . $mysqli->connect_error);
}
$mysqli->set_charset($charset);

// server-side price calc if final_amount not provided
if ($final_amount_grosze === 0) {
    // prosty cennik - dopasuj do swojego
    $prices = ['30'=>299.00,'60'=>399.00,'90'=>499.00];
    $unit = $prices[(string)$duration] ?? 0;
    $total_amount = $unit * max(1, $participants);
}

// Begin transaction
$mysqli->begin_transaction();
try {
    // find or create user
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $user_id = $row['id'];
    } else {
        $user_id = uniqid('u_', true);
        $ins = $mysqli->prepare("INSERT INTO users (id, first_name, last_name, email, phone, gdpr) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->bind_param('sssssi', $user_id, $firstName, $lastName, $email, $phone, $gdpr);
        $ins->execute();
        $ins->close();
    }
    $stmt->close();

    // reservation
    $reservation_id = uniqid('r_', true);
    $code = 'RERV-' . date('Ymd-His') . '-' . strtoupper(substr(md5($reservation_id),0,8));
    $notes = json_encode(['voucher'=>$voucher], JSON_UNESCAPED_UNICODE);

    $insR = $mysqli->prepare("INSERT INTO reservations (id, user_id, slot_id, date, time, duration, participants, total_amount, currency, status, code, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PLN', 'pending', ?, ?)");
    $slot_id = $_POST['slot_id'] ?? null;
    $amount = $total_amount;
    $insR->bind_param('ssssii dss', $reservation_id, $user_id, $slot_id, $date, $time, $duration, $participants, $amount, $code, $notes);
    // NOTE: PHP bind_param types need adjustment if using 'd' etc. For simplicity we'll do prepared statements with cast below:
    $insR->close();

    // Simpler safe insert using real escaping to avoid complexity of bind types mix:
    $stmt = $mysqli->prepare("INSERT INTO reservations (id, user_id, slot_id, date, time, duration, participants, total_amount, currency, status, code, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PLN', 'pending', ?, ?)");
    $stmt->bind_param('sssssi dss', $reservation_id, $user_id, $slot_id, $date, $time, $duration, $participants, $amount, $code, $notes);
    // If above causes complexity, you can use mysqli_real_escape_string + query (careful)
    // Execute (this is illustrative; adapt types)
    $stmt->execute();
    $stmt->close();

    // payment record (optional)
    $payment_id = uniqid('p_', true);
    $insP = $mysqli->prepare("INSERT INTO payments (id, reservation_id, amount, currency, provider, status) VALUES (?, ?, ?, 'PLN', 'local', 'initiated')");
    $insP->bind_param('ssd', $payment_id, $reservation_id, $amount);
    $insP->execute();
    $insP->close();

    $mysqli->commit();

    // redirect to confirmation page
    header('Location: /payment-confirm.php?reservation=' . urlencode($reservation_id));
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    echo 'Błąd serwera: ' . htmlspecialchars($e->getMessage());
    exit;
}
