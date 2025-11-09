<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db_connect.php';

function generateToken() {
    return bin2hex(random_bytes(16));
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPhone(string $phone): bool {
    return preg_match('/^\+?[0-9\s\-]{7,20}$/', $phone) === 1;
}

$post = $_POST;

$firstName = trim($post['firstName'] ?? '');
$lastName = trim($post['lastName'] ?? '');
$email = trim($post['email'] ?? '');
$phone = trim($post['phone'] ?? '');
$participants  = isset($post['participants']) ? (int)$post['participants'] : 1;
$slot_id = trim($post['slot_id'] ?? '');
$voucher = trim($post['applied_voucher'] ?? '');
$gdpr = isset($post['gdpr']) ? 1 : 0;

$errors = [];

if ($firstName === '' || mb_strlen($firstName) < 2) {
    $errors['firstName'] = 'Wprowadź imię (min. 2 znaki)';
}

if ($lastName === '' || mb_strlen($lastName) < 2) {
    $errors['lastName'] = 'Wprowadź nazwisko (min. 2 znaki)';
}

if ($email === '' || !isValidEmail($email)) {
    $errors['email'] = 'Nieprawidłowy adres e-mail';
}

if ($phone === '' || !isValidPhone($phone)) {
    $errors['phone'] = 'Nieprawidłowy numer telefonu';
}

if ($participants < 1 || $participants > 50) {
    $errors['participants'] = 'Nieprawidłowa liczba uczestników';
}

if ($slot_id === '') {
    $errors['slot_id'] = 'Brak wybranego slotu rezerwacji';
}

if (!$gdpr) {
    $errors['gdpr'] = 'Zgoda RODO jest wymagana';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => mb_strtolower($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = $user['id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO users (id, first_name, last_name, email, phone, gdpr_consent)
            VALUES (UUID(), :first_name, :last_name, :email, :phone, :gdpr)
        ");
        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email' => mb_strtolower($email),
            ':phone' => $phone,
            ':gdpr' => $gdpr
        ]);
        $user_id = $pdo->query("SELECT id FROM users WHERE email = '" . mb_strtolower($email) . "' LIMIT 1")->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT start_ts, end_ts, capacity FROM calendar_slots WHERE id = :slot_id AND is_active = 1 FOR UPDATE");
    $stmt->execute(['slot_id' => $slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception('Wybrany slot nie istnieje lub jest nieaktywny');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE slot_id = :slot_id AND status IN ('pending','paid')");
    $stmt->execute(['slot_id' => $slot_id]);
    $reserved_count = (int)$stmt->fetchColumn();

    if ($reserved_count + $participants > (int)$slot['capacity']) {
        throw new Exception('Wybrany slot jest już zajęty');
    }

    $duration_minutes = 30;
    $stmt = $pdo->prepare("SELECT id, price_gross, promo_price_gross FROM prices WHERE minutes = :minutes LIMIT 1");
    $stmt->execute(['minutes' => $duration_minutes]);
    $price = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$price) {
        throw new Exception('Nie znaleziono ceny dla wybranej długości');
    }

    $total_amount = $price['promo_price_gross'] ?? $price['price_gross'];

    $coupon_id = null;

    if ($voucher) {
        $stmt = $pdo->prepare("
            SELECT id, discount_percent, discount_amount, active, valid_from, valid_to, max_redemptions, redeemed_count
            FROM coupons
            WHERE code = :code AND active = 1
            LIMIT 1
        ");
        $stmt->execute(['code' => strtoupper(trim($voucher))]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($coupon) {
            $today = date('Y-m-d');
            if (($coupon['valid_from'] && $coupon['valid_from'] > $today) || ($coupon['valid_to'] && $coupon['valid_to'] < $today)) {
                $coupon = null;
            }
        }

        if ($coupon && $coupon['redeemed_count'] >= $coupon['max_redemptions']) {
            $coupon = null;
        }

        if ($coupon) {
            $coupon_id = $coupon['id'];

            if ($coupon['discount_amount']) {
                $total_amount -= $coupon['discount_amount'];
            } elseif ($coupon['discount_percent']) {
                $total_amount *= (1 - $coupon['discount_percent'] / 100);
            }
        }
    }

    if ($total_amount < 0) {
        $total_amount = 0;
    }

    $reservation_id = generateToken();
    $booking_code = 'RERV-' . date('Ymd-His') . '-' . substr($reservation_id, 0, 8);

    $stmt = $pdo->prepare("
        INSERT INTO reservations
            (id, user_id, slot_id, price_id, coupon_id, participants_count, status, total_amount, currency, code, gdpr_consent)
        VALUES
            (:id, :user_id, :slot_id, :price_id, :coupon_id, :participants_count, 'pending', :total_amount, 'PLN', :code, :gdpr)
    ");
    $stmt->execute([
        ':id' => $reservation_id,
        ':user_id' => $user_id,
        ':slot_id' => $slot_id,
        ':price_id' => $price['id'],
        ':coupon_id' => $coupon_id,
        ':participants_count' => $participants,
        ':total_amount' => $total_amount,
        ':code' => $booking_code,
        ':gdpr' => $gdpr
    ]);

    if ($coupon_id) {
        $stmt = $pdo->prepare("UPDATE coupons SET redeemed_count = redeemed_count + 1 WHERE id = :id");
        $stmt->execute(['id' => $coupon_id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'reservation_id' => $reservation_id,
        'booking_code' => $booking_code,
        'total_amount' => $total_amount,
        'currency' => 'PLN',
        'message' => 'Rezerwacja zapisana poprawnie'
    ]);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
