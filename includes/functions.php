<?php
declare(strict_types=1);

/**
 * functions.php
 *
 * Zestaw helperów używanych przez aplikację:
 * - sesja: session_ensure_started()
 * - CSRF: generate_csrf_token(), verify_csrf_token()
 * - JSON responses: json_response()
 * - redirect(): bezpieczne przekierowanie
 * - sanitize_input(), validate_email()
 * - format_price(), generate_reservation_code()
 * - log_error(): prosty logger (zapis w APP_LOG_DIR)
 * - render_template(): prosty loader szablonów PHP
 * - daty/czasy: now(), format_datetime(), parse_to_timezone(), convert_timezone()
 *
 * Założenia:
 * - Jeśli dostępna jest funkcja env(), zostanie użyta do pobrania APP_TZ i innych ustawień.
 * - APP_LOG_DIR i APP_CSRF_TTL mogą być zdefiniowane wcześniej (np. w config.php). Jeżeli nie, użyte zostaną sensowne domyślne wartości.
 *
 * NOTKA: Plik nie powinien przy include wykonywać żadnych operacji (nie ma side-effects).
 */

/* --- Domyślne stałe jeżeli nie zdefiniowane --- */
if (!defined('APP_LOG_DIR')) {
    define('APP_LOG_DIR', __DIR__ . '/logs');
}
if (!defined('APP_CSRF_TTL')) {
    // TTL tokenu CSRF w sekundach (1h)
    define('APP_CSRF_TTL', 3600);
}
if (!defined('APP_RESERVATION_PREFIX')) {
    define('APP_RESERVATION_PREFIX', 'VECTRON');
}

/* --- Helper: env() fallback jeżeli nie ma w projekcie --- */
if (!function_exists('env')) {
    /**
     * Pobiera wartość ze środowiska (getenv / _ENV / _SERVER). Zwraca $default jeżeli brak.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $v = getenv($key);
        if ($v !== false) return $v;
        if (isset($_ENV[$key])) return $_ENV[$key];
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        return $default;
    }
}

/* --- TIMEZONE helper (używa APP_TZ z env jeśli dostępne) --- */
/**
 * Zwraca nazwę strefy czasowej aplikacji (np. 'Europe/Bucharest').
 * Sprawdza: stałą APP_TZ, env('APP_TZ'), domyślnie 'Europe/Bucharest'.
 */
function app_timezone(): string
{
    if (defined('APP_TZ')) {
        return APP_TZ;
    }
    $tz = env('APP_TZ', null);
    if ($tz && is_string($tz) && $tz !== '') {
        return $tz;
    }
    return 'Europe/Bucharest';
}

/* --- Session helpers --- */
/**
 * Upewnij się, że sesja jest uruchomiona.
 * Nie ustawia cookie params — to powinien robić config.php centralnie.
 */
function session_ensure_started(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/* --- CSRF tokeny (z TTL) --- */
/**
 * Generuje i zapisuje token CSRF w sesji.
 * @return string
 */
function generate_csrf_token(): string
{
    session_ensure_started();
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // fallback
        $token = bin2hex(openssl_random_pseudo_bytes(32));
    }
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

/**
 * Weryfikuje token CSRF zapisany w sesji. Sprawdza TTL (APP_CSRF_TTL).
 * @param string|null $token
 * @param bool $invalidateAfterUse opcjonalnie unieważnia token po użyciu
 * @return bool
 */
function verify_csrf_token(?string $token, bool $invalidateAfterUse = false): bool
{
    if (empty($token)) {
        return false;
    }
    session_ensure_started();
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    $stored = $_SESSION['csrf_token'];
    $time = (int)($_SESSION['csrf_token_time'] ?? 0);

    if (defined('APP_CSRF_TTL') && APP_CSRF_TTL > 0) {
        if (($time + APP_CSRF_TTL) < time()) {
            // token wygasł
            return false;
        }
    }

    $valid = hash_equals($stored, $token);

    if ($valid && $invalidateAfterUse) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }

    return $valid;
}

/* --- JSON response --- */
/**
 * Wysyła bezpieczny JSON response i zakończenie skryptu.
 * @param mixed $data
 * @param int $code
 * @return void
 */
function json_response($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        // domyślnie nie cache'ujemy
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $err = json_last_error_msg();
        $payload = ['error' => 'json_encode_error', 'message' => $err];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $json;
    exit;
}

/* --- Redirect (bezpieczny) --- */
/**
 * Przekierowuje użytkownika na URL. Jeśli nagłówki wysłane - używa JS/meta refresh.
 * @param string $url
 * @return void
 */
function redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href = ' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" /></noscript>';
    }
    exit;
}

/* --- Input sanitization --- */
/**
 * Rekurencyjna sanitizacja wejścia.
 * - dla stringów: trim, usuwa kontrolne znaki, strip_tags
 * - dla tablic: mapuje rekurencyjnie
 * @param mixed $data
 * @return mixed
 */
function sanitize_input($data)
{
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = sanitize_input($v);
        }
        return $out;
    }

    if (is_string($data)) {
        $s = trim($data);
        // usuń kontrolne znaki
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', '', $s);
        // podstawowa sanitizacja HTML (usuń tagi)
        $s = strip_tags($s);
        return $s;
    }

    // inne typy (int/float/null/boolean) zwracamy bez zmian
    return $data;
}

/* --- Email validation --- */
/**
 * Waliduje adres email w bezpieczny sposób.
 * @param string $email
 * @return bool
 */
function validate_email(string $email): bool
{
    $email = trim($email);
    if ($email === '') return false;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    // Dodatkowy regex, pozwalający na międzynarodne znaki w lokalnej części
    $pattern = '/^[A-Za-z0-9._%+\-\u0080-\uFFFF]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/u';
    return preg_match($pattern, $email) === 1;
}

/* --- Formatowanie cen --- */
/**
 * format_price(299.5) => "299.50 zł"
 * @param float|int|string $amount
 * @param string $currency
 * @return string
 */
function format_price($amount, string $currency = 'zł'): string
{
    $float = (float)$amount;
    $formatted = number_format($float, 2, '.', '');
    return $formatted . ' ' . $currency;
}

/* --- Generate reservation code --- */
/**
 * Generuje unikalny kod rezerwacji: PREFIX-YYYYMMDD-XXXX
 * @param string|null $prefix
 * @return string
 */
function generate_reservation_code(string $prefix = null): string
{
    $prefix = $prefix ?? APP_RESERVATION_PREFIX;
    $date = gmdate('Ymd'); // używamy UTC dla deterministyczności kodu
    try {
        $rand = strtoupper(bin2hex(random_bytes(2))); // 4 znaki hex
    } catch (Exception $e) {
        $rand = strtoupper(substr(bin2hex(openssl_random_pseudo_bytes(2)), 0, 4));
    }
    return sprintf('%s-%s-%s', $prefix, $date, $rand);
}

/* --- Simple logging --- */
/**
 * Prosty logger do pliku. Tworzy katalog jeżeli nie istnieje.
 * Nie zapisuj w logach haseł/sekretów.
 * @param string $msg
 * @param string|null $file (pełna ścieżka) - jeśli null, używa APP_LOG_DIR/app.log
 * @return void
 */
function log_error(string $msg, string $file = null): void
{
    $dir = APP_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $file ?? ($dir . '/app.log');

    $tz = new DateTimeZone(app_timezone());
    $time = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
    $entry = sprintf("[%s] %s\n", $time, trim($msg));

    // Bezpieczny zapis z blokadą
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

/* --- Template rendering (PHP partials) --- */
/**
 * Renderuje plik PHP jako szablon. Bazuje na include.
 * @param string $path - pełna ścieżka do pliku
 * @param array $vars - zmienne do podania do szablonu
 * @param bool $return - jeśli true, zwraca zawartość zamiast echo
 * @return string|null
 * @throws InvalidArgumentException
 */
function render_template(string $path, array $vars = [], bool $return = false)
{
    if (!file_exists($path)) {
        throw new InvalidArgumentException("Template not found: $path");
    }

    extract($vars, EXTR_SKIP);

    ob_start();
    include $path;
    $content = ob_get_clean();

    if ($return) {
        return $content;
    }

    echo $content;
    return null;
}

/* --- Date/time helpers (używają app_timezone()) --- */
/**
 * Zwraca DateTime w strefie aplikacji
 * @return DateTime
 */
function now(): DateTime
{
    return new DateTime('now', new DateTimeZone(app_timezone()));
}

/**
 * Formatuje datetime lub string do formatu w strefie aplikacji.
 * @param DateTime|string|int $datetime
 * @param string $format
 * @return string
 */
function format_datetime($datetime, string $format = 'Y-m-d H:i:s'): string
{
    if ($datetime instanceof DateTime) {
        $dt = clone $datetime;
    } elseif (is_int($datetime)) {
        $dt = new DateTime('@' . $datetime);
    } else {
        $dt = new DateTime((string)$datetime);
    }
    $dt->setTimezone(new DateTimeZone(app_timezone()));
    return $dt->format($format);
}

/**
 * Parsuje string do DateTime w strefie aplikacji.
 * @param string $timestr
 * @return DateTime
 * @throws Exception
 */
function parse_to_timezone(string $timestr): DateTime
{
    $dt = new DateTime($timestr);
    $dt->setTimezone(new DateTimeZone(app_timezone()));
    return $dt;
}

/**
 * Konwertuje DateTime na inną strefę
 * @param DateTime $dt
 * @param string $tz
 * @return DateTime
 */
function convert_timezone(DateTime $dt, string $tz = 'Europe/Warsaw'): DateTime
{
    $dt->setTimezone(new DateTimeZone($tz));
    return $dt;
}

/* --- Useful alias --- */
/**
 * Zwraca ISO 8601 (DATE_ATOM) w strefie aplikacji
 * @return string
 */
function now_iso(): string
{
    return now()->format(DATE_ATOM);
}

/* --- Small utilities --- */
/**
 * Bezpieczne HTML-escape (alias)
 * @param mixed $str
 * @return string
 */
function esc($str): string
{
    if ($str === null) return '';
    if (is_array($str)) return '';
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --- End of functions.php --- */
