<?php
requireAdmin();
requireCSRF();

$booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
$status = isset($_POST['status']) ? $_POST['status'] : null;
$note = isset($_POST['note']) ? $_POST['note'] : null;

if (!$booking_id) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare('SELECT status, email FROM bookings WHERE id = ?');
$stmt->execute([$booking_id]);
$old = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    http_response_code(404);
    exit;
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE bookings SET status = ?, note = ? WHERE id = ?');
    $update->execute([$status, $note, $booking_id]);

    $log = $pdo->prepare(
        'INSERT INTO booking_logs (booking_id, changed_by, old_status, new_status, note, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $changed_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $log->execute([$booking_id, $changed_by, $old['status'], $status, $note]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit;
}

if ($old['status'] !== 'paid' && $status === 'paid') {
    require_once __DIR__ . '/../includes/mailer.php';
    $mailer = new Mailer();
    $subject  = "Płatność otrzymana — rezerwacja #{$booking_id}";
    $bodyHtml = "<p>Twoja rezerwacja #{$booking_id} została opłacona.</p>";
    $bodyText = "Twoja rezerwacja #{$booking_id} została opłacona.";
    $mailer->sendMail($old['email'], $subject, $bodyHtml, $bodyText);
}

echo 'OK';
