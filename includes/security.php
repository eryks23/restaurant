<?php
// security.php
// W pełni poprawny plik bezpieczeństwa dla projektu rezerwacji
// Autor: wygenerowane przez ChatGPT
// Cel: CSRF, rate limiting, HTTPS, nagłówki, sanitizacja, session hardening

// =======================
// KONFIGURACJA
// =======================
define('RATE_LIMIT_MAX_REQUESTS', 100);   // maksymalna liczba requestów
define('RATE_LIMIT_WINDOW_SEC', 60);      // czas okna rate limit w sekundach
define('CSRF_TOKEN_NAME', '_csrf_token'); // nazwa tokenu w formularzach
define('LOCK_FILE_DIR', __DIR__ . '/../tmp'); // katalog do plików lock/rate limit

// Ustawienie bezpiecznej strefy czasowej
date_default_timezone_set('Europe/Warsaw');

// =======================
// SESJE I COOKIE SECURITY
// =======================
function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'],
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => true,               // tylko HTTPS
            'httponly' => true,             // brak dostępu JS
            'samesite' => 'Lax'             // Lax lub Strict dla admina
        ]);
        session_start();
        session_regenerate_id(true);       // unikanie session fixation
    }
}

// =======================
// FORCE HTTPS
// =======================
function require_https() {
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ) {
        return; // HTTPS już używane
    }
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirect, true, 301);
    exit();
}

// =======================
// BEZPIECZNE NAGŁÓWKI
// =======================
function set_security_headers() {
    header('X-Frame-Options: SAMEORIGIN'); // blokuje clickjacking
    header('X-Content-Type-Options: nosniff'); // zapobiega MIME sniffing
    header('Referrer-Policy: no-referrer-when-downgrade');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' https://maps.googleapis.com; style-src \'self\' https://fonts.googleapis.com; frame-src https://www.google.com;');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload'); // HSTS
}

// =======================
// CSRF TOKEN
// =======================
function generate_csrf_token() {
    secure_session_start();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verify_csrf_token($token) {
    secure_session_start();
    if (empty($token) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// =======================
// RATE LIMITING (IP)
// =======================
function rate_limit($key_prefix = 'rl') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = LOCK_FILE_DIR . '/' . $key_prefix . '_' . md5($ip) . '.lock';

    if (!file_exists(LOCK_FILE_DIR)) {
        mkdir(LOCK_FILE_DIR, 0755, true);
    }

    $count = 0;
    $time = time();

    // Bezpieczne odczytanie pliku lock
    if (file_exists($file)) {
        $data = file_get_contents($file);
        list($last_time, $last_count) = explode('|', $data);
        $last_time = (int)$last_time;
        $last_count = (int)$last_count;

        if ($time - $last_time < RATE_LIMIT_WINDOW_SEC) {
            $count = $last_count;
        }
    }

    $count++;
    file_put_contents($file, $time . '|' . $count, LOCK_EX);

    if ($count > RATE_LIMIT_MAX_REQUESTS) {
        header('HTTP/1.1 429 Too Many Requests');
        echo json_encode(['error' => 'rate_limit', 'message' => 'Zbyt wiele żądań, spróbuj później.']);
        exit();
    }
}

// =======================
// INPUT SANITIZATION
// =======================
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// =======================
// OUTPUT JSON / ERROR
// =======================
function json_response($data, $http_code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($http_code);
    echo json_encode($data);
    exit();
}

// =======================
// LOGOWANIE ZDARZEŃ BEZ PII
// =======================
function security_log($message) {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
    $file = $log_dir . '/security.log';
    $line = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// =======================
// FUNKCJE DODATKOWE
// =======================
function abort_if_not_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'method_not_allowed', 'message' => 'Tylko POST dozwolony'], 405);
    }
}

// =======================
// AUTO INIT (opcjonalnie)
// =======================
secure_session_start();
set_security_headers();
require_https();

?>
