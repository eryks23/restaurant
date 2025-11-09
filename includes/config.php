<?php
/**
 * config.php
 *
 * Plik konfiguracyjny aplikacji (umieść poza webroot, np. /var/www/vectron/config.php).
 *
 * Zasady:
 *  - Sekrety (DB_PASS, P24_CRC, SMTP_PASSWORD) trzymamy w .env poza webroot.
 *  - Nie commituj .env do repo.
 *
 * Uwaga: plik nie powinien wykonywać akcji (send email itp.) przy samym include.
 */

declare(strict_types=1);

/* --- ZABEZPIECZENIE: zablokuj bezpośredni dostęp przez przeglądarkę --- */
if (php_sapi_name() !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('403 - Access denied');
}

/* --- ŁADOWANIE .env (preferuj plik poza webroot) --- */
$envCandidates = [
    // Preferowane lokalizacje (odnajdź .env poza webroot)
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
];

foreach ($envCandidates as $envPath) {
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'"); // usuń otaczające cudzysłowy
            // nie nadpisujemy jeśli już w środowisku
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        break;
    }
}

/* --- Helper: env() --- */
if (!function_exists('env')) {
    /**
     * Pobiera wartość zmiennej środowiskowej.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null) {
        $v = getenv($key);
        if ($v !== false) return $v;
        if (array_key_exists($key, $_ENV)) return $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
        return $default;
    }
}

/* --- Środowisko i błędy --- */
$APP_ENV = env('APP_ENV', 'production'); // 'production' lub 'development'
defined('APP_ENV') or define('APP_ENV', $APP_ENV);

// Strefa czasowa: wymagana w specyfikacji Europe/Bucharest
date_default_timezone_set(env('APP_TZ', 'Europe/Bucharest'));

/* Display / log errors */
if (APP_ENV === 'development') {
    ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // folder poza webroot
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    $errorLog = env('PHP_ERROR_LOG', '/var/log/vectron_sim_php_errors.log');
    $errorLogDir = dirname($errorLog);
    if (@is_dir($errorLogDir) && @is_writable($errorLogDir)) {
        ini_set('error_log', $errorLog);
    } else {
        ini_set('error_log', 'syslog');
    }
}

/* --- KONFIGURACJA DB (używaj DB przez get_db() w db_connect.php) --- */
/* Tutaj definiujemy tylko stałe/ENV — logika połączenia w db_connect.php */
defined('DB_HOST') or define('DB_HOST', env('DB_HOST', '127.0.0.1'));
defined('DB_PORT') or define('DB_PORT', env('DB_PORT', '3306'));
defined('DB_NAME') or define('DB_NAME', env('DB_NAME', 'vectron_sim'));
defined('DB_USER') or define('DB_USER', env('DB_USER', 'vectron_user'));
defined('DB_PASS') or define('DB_PASS', env('DB_PASS', ''));

/* --- Aplikacja / site --- */
defined('SITE_NAME') or define('SITE_NAME', env('SITE_NAME', 'Vectron UTK SIM'));

/* --- P24 (Przelewy24) --- */
/* Sekrety: P24_MERCHANT_ID, P24_POS_ID, P24_CRC powinny być w .env */
defined('P24_MERCHANT_ID') or define('P24_MERCHANT_ID', env('P24_MERCHANT_ID', ''));
defined('P24_POS_ID')      or define('P24_POS_ID', env('P24_POS_ID', ''));
defined('P24_CRC')         or define('P24_CRC', env('P24_CRC', ''));
defined('P24_SANDBOX')     or define('P24_SANDBOX', filter_var(env('P24_SANDBOX', 'true'), FILTER_VALIDATE_BOOLEAN));
defined('P24_RETURN_URL')  or define('P24_RETURN_URL', env('P24_RETURN_URL', SITE_URL . '/p24/return.php'));
defined('P24_NOTIFY_URL')  or define('P24_NOTIFY_URL', env('P24_NOTIFY_URL', SITE_URL . '/p24/notify.php'));

/* --- SMTP / mailing --- */
/* Używaj tych samych nazw env w całym projekcie */
defined('SMTP_HOST')         or define('SMTP_HOST', env('SMTP_HOST', ''));
defined('SMTP_PORT')         or define('SMTP_PORT', (int)env('SMTP_PORT', 587));
defined('SMTP_USERNAME')     or define('SMTP_USERNAME', env('SMTP_USERNAME', ''));
defined('SMTP_PASSWORD')     or define('SMTP_PASSWORD', env('SMTP_PASSWORD', ''));
defined('SMTP_FROM_EMAIL')   or define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', env('MAIL_FROM', 'symulator@kgrail.pl')));
defined('SMTP_FROM_NAME')    or define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'Vectron UTK SIM')));
defined('SMTP_REPLY_TO')     or define('SMTP_REPLY_TO', env('SMTP_REPLY_TO', SMTP_FROM_EMAIL));

/* --- Inne ustawienia aplikacji --- */
defined('APP_LOG_DIR') or define('APP_LOG_DIR', env('APP_LOG_DIR', __DIR__ . '/logs'));
defined('APP_CSRF_TTL') or define('APP_CSRF_TTL', (int)env('APP_CSRF_TTL', 3600));
defined('APP_RESERVATION_PREFIX') or define('APP_RESERVATION_PREFIX', env('APP_RESERVATION_PREFIX', 'VECTRON'));

/* --- Bezpieczne ustawienia sesji --- */
/*
 * W produkcji zalecam ustawienie SESSION_COOKIE_DOMAIN w .env (np. '.symulatorvectron.pl').
 * Jeżeli aplikacja działa za reverse proxy, ustaw X-Forwarded-Proto i użyj go do wykrywania HTTPS.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    $cookieDomain = env('SESSION_COOKIE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST) ?: '');

    // Samesite: domyślnie 'Lax' (umożliwia powrót z zewnętrznych płatności),
    // dla panelu admin możesz rozważyć 'Strict' ustawiając oddzielne cookie.
    $samesite = env('SESSION_SAMESITE', 'Lax');

    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $cookieDomain,
        'secure'   => (bool)$isSecure,
        'httponly' => true,
        'samesite' => $samesite,
    ];

    // PHP >= 7.3 obsługuje tablicowy sposób
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        // starsze PHP: fallback (dodaj ręcznie samesite do path)
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_start();
}

/* --- Minimalne nagłówki bezpieczeństwa --- */
if (php_sapi_name() !== 'cli') {
    // usuń nagłówek X-Powered-By
    header_remove('X-Powered-By');

    if (!headers_sent()) {
        // X-Frame-Options: użyj SAMEORIGIN jeśli potrzebujesz iframe Google Maps
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer-when-downgrade');

        // HSTS tylko w produkcji i gdy HTTPS
        if (APP_ENV !== 'development' && (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        )) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }

        // Domyślne CSP - dopasuj do używanych zewnętrznych zasobów (maps, cdn, analytics)
        // UWAGA: dostosuj script-src/style-src jeśli korzystasz z CDN/GoogleMaps/Analytics
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://maps.googleapis.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-src https://www.google.com https://maps.google.com; object-src 'none'; base-uri 'self';");
    }
}

/* --- Helper: require_https() z obsługą reverse proxy --- */
if (!function_exists('require_https')) {
    function require_https(): void
    {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        if (!$isSecure) {
            $host = $_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST);
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $url = 'https://' . $host . $uri;
            header('Location: ' . $url, true, 301);
            exit;
        }
    }
}

/* --- Końcowa uwaga / instrukcja dla dewelopera --- */
/**
 * - Upewnij się, że plik .env (poza webroot) zawiera:
 *     APP_ENV=production
 *     APP_TZ=Europe/Bucharest
 *     DB_HOST=...
 *     DB_PORT=3306
 *     DB_NAME=...
 *     DB_USER=...
 *     DB_PASS=...
 *     P24_MERCHANT_ID=...
 *     P24_POS_ID=...
 *     P24_CRC=...
 *     P24_SANDBOX=true
 *     SMTP_HOST=...
 *     SMTP_PORT=587
 *     SMTP_USERNAME=...
 *     SMTP_PASSWORD=...
 *     SMTP_FROM_EMAIL=...
 *     SMTP_FROM_NAME=...
 *     
 *
 * - Zainstaluj/konfiguruj db_connect.php aby używał powyższych stałych/ENV.
 * - Nie commituj .env do repo; zamiast tego dodaj .env.example.
 */

return [
    'env' => APP_ENV,
    'site_url' => SITE_URL,
    'site_name' => SITE_NAME,
    'db' => [
        'host' => DB_HOST,
        'port' => DB_PORT,
        'name' => DB_NAME,
        'user' => DB_USER,
    ],
    'p24' => [
        'merchant_id' => P24_MERCHANT_ID,
        'pos_id' => P24_POS_ID,
        'crc' => P24_CRC,
        'sandbox' => P24_SANDBOX,
        'return_url' => P24_RETURN_URL,
        'notify_url' => P24_NOTIFY_URL,
    ],
    'smtp' => [
        'host' => SMTP_HOST,
        'port' => SMTP_PORT,
        'username' => SMTP_USERNAME,
        // password intentionally omitted from return for safety
        'from_email' => SMTP_FROM_EMAIL,
        'from_name' => SMTP_FROM_NAME,
        'reply_to' => SMTP_REPLY_TO,
    ],
    'app' => [
        'csrf_ttl' => APP_CSRF_TTL,
        'reservation_prefix' => APP_RESERVATION_PREFIX,
        'log_dir' => APP_LOG_DIR,
    ]
];
