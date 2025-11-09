<?php
declare(strict_types=1);

/**
 * auth.php
 * Ulepszony moduł autoryzacji admina (bez własnego get_db()).
 *
 * Wymaga:
 *  - db_connect.php (funkcja get_db(): PDO)
 *  - opcjonalnie functions.php z funkcjami CSRF: generate_csrf_token(), verify_csrf_token()
 *
 * Zmiany / założenia:
 *  - nie definiujemy get_db() (użyj db_connect.php)
 *  - init_auth_tables() tworzy tylko niezbędne tabele admins i login_attempts (idempotentnie)
 *  - clear_failed_attempts() usuwa nieudane próby dla user/ip (natychmiast po poprawnym logowaniu)
 */

// --- Konfiguracja domyślna (możesz przesłonić w .env / config.php) ---
if (!defined('MAX_FAILED_ATTEMPTS')) define('MAX_FAILED_ATTEMPTS', 5);
if (!defined('ATTEMPT_WINDOW_SECONDS')) define('ATTEMPT_WINDOW_SECONDS', 15 * 60);
if (!defined('LOCKOUT_SECONDS')) define('LOCKOUT_SECONDS', 15 * 60);
if (!defined('LOGIN_REDIRECT')) define('LOGIN_REDIRECT', '/admin/login');

// --- Requires (spróbuj dołączyć db_connect.php jeśli nie załadowano) ---
if (!function_exists('get_db')) {
    $dbConnect = __DIR__ . '/db_connect.php';
    if (is_file($dbConnect)) {
        require_once $dbConnect;
    } else {
        // Jeżeli get_db() nadal nie jest dostępne, rzuć czytelny błąd
        throw new RuntimeException('db_connect.php (get_db) is required by auth.php');
    }
}

// --- SESSION ---
function init_session(): void
{
    ini_set('session.use_strict_mode', '1');

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => defined('SESSION_COOKIE_DOMAIN') ? SESSION_COOKIE_DOMAIN : (parse_url((defined('SITE_URL') ? SITE_URL : ''), PHP_URL_HOST) ?: ''),
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => defined('SESSION_SAMESITE') ? SESSION_SAMESITE : 'Strict'
    ];

    // Safe session cookie params for older PHP versions handled gracefully
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

// --- DB: tworzenie niezbędnych tabel dla auth (idempotentne) ---
function init_auth_tables(): void
{
    $pdo = get_db();
    // admins
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'admin',
            created_at INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // login_attempts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191),
            ip VARCHAR(45) NOT NULL,
            attempt_time INT NOT NULL,
            success TINYINT(1) NOT NULL,
            INDEX (attempt_time),
            INDEX (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// --- CSRF helpers (użyj istniejącej implementacji jeśli dostępna) ---
function _verify_csrf_fallback(?string $token): bool
{
    if (empty($token)) return false;
    if (session_status() !== PHP_SESSION_ACTIVE) init_session();
    if (empty($_SESSION['_csrf_token'])) return false;
    return hash_equals((string)$_SESSION['_csrf_token'], (string)$token);
}

function verify_csrf_optional(?string $token): bool
{
    // Jeśli projekt ma verify_csrf_token() użyj jej — preferujemy centralną implementację
    if (function_exists('verify_csrf_token')) {
        return verify_csrf_token($token);
    }
    // Albo inna nazwa verify_csrf
    if (function_exists('verify_csrf')) {
        return verify_csrf($token);
    }
    // Fallback do prostej implementacji opierającej się na $_SESSION['_csrf_token']
    return _verify_csrf_fallback($token);
}

// --- Admin account management ---
function create_admin(string $username, string $password, string $role = 'admin'): bool
{
    $pdo = get_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, created_at) VALUES (:u, :h, :r, :t)");
    try {
        return (bool)$stmt->execute([':u' => $username, ':h' => $hash, ':r' => $role, ':t' => $now]);
    } catch (PDOException $e) {
        error_log("create_admin error: " . $e->getMessage());
        return false;
    }
}

function get_admin_by_username(string $username): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// --- IP helper (proxy-aware) ---
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return trim((string)$_SERVER['HTTP_X_REAL_IP']);
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) return trim((string)$_SERVER['REMOTE_ADDR']);
    return '0.0.0.0';
}

// --- Login attempts recording / counting / lockout ---
function record_login_attempt(?string $username, bool $success): void
{
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip, attempt_time, success) VALUES (:u, :ip, :t, :s)");
        $stmt->execute([
            ':u' => $username,
            ':ip' => get_client_ip(),
            ':t' => time(),
            ':s' => $success ? 1 : 0
        ]);
    } catch (Exception $e) {
        error_log("record_login_attempt: " . $e->getMessage());
    }
}

function count_failed_attempts(?string $username, int $windowSeconds = ATTEMPT_WINDOW_SECONDS): int
{
    $pdo = get_db();
    $since = time() - $windowSeconds;
    $ip = get_client_ip();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempt_time >= :since AND (username = :u OR ip = :ip)");
    $stmt->execute([':since' => $since, ':u' => $username, ':ip' => $ip]);
    return (int)$stmt->fetchColumn();
}

function lockout_remaining_seconds(?string $username): int
{
    $pdo = get_db();
    $since = time() - ATTEMPT_WINDOW_SECONDS;
    $ip = get_client_ip();
    $stmt = $pdo->prepare("SELECT MAX(attempt_time) FROM login_attempts WHERE success = 0 AND (username = :u OR ip = :ip) AND attempt_time >= :since");
    $stmt->execute([':u' => $username, ':ip' => $ip, ':since' => $since]);
    $last = (int)$stmt->fetchColumn();
    if ($last === 0) return 0;
    $count = count_failed_attempts($username);
    if ($count >= MAX_FAILED_ATTEMPTS) {
        $unlockAt = $last + LOCKOUT_SECONDS;
        $remaining = $unlockAt - time();
        return $remaining > 0 ? $remaining : 0;
    }
    return 0;
}

// Poprawiona funkcja: usuwa nieudane próby dla danego username lub IP (natychmiastowo)
function clear_failed_attempts(?string $username): void
{
    try {
        $pdo = get_db();
        $ip = get_client_ip();
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE success = 0 AND (username = :u OR ip = :ip)");
        $stmt->execute([':u' => $username, ':ip' => $ip]);
    } catch (Exception $e) {
        error_log("clear_failed_attempts: " . $e->getMessage());
    }
}

// --- Login / logout flow ---
/**
 * admin_login
 * @param string $username
 * @param string $password
 * @param string|null $csrf
 * @return array ['ok' => bool, 'error' => string|null, 'remaining' => int|null]
 */
function admin_login(string $username, string $password, ?string $csrf = null): array
{
    $username = trim($username);

    // verify CSRF (preferuj centralną implementację)
    if (!verify_csrf_optional($csrf)) {
        return ['ok' => false, 'error' => 'csrf'];
    }

    $remaining = lockout_remaining_seconds($username);
    if ($remaining > 0) {
        return ['ok' => false, 'error' => 'locked', 'remaining' => $remaining];
    }

    $user = get_admin_by_username($username);
    $dbPasswordHash = $user['password_hash'] ?? null;

    // Dummy hash to mitigate user enumeration timing attacks
    static $dummyHash = null;
    if ($dummyHash === null) $dummyHash = password_hash('', PASSWORD_DEFAULT);

    $hashToVerify = $dbPasswordHash ?? $dummyHash;
    $verified = password_verify($password, $hashToVerify);

    if ($verified && $user) {
        record_login_attempt($username, true);
        // secure session management
        if (session_status() !== PHP_SESSION_ACTIVE) init_session();
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $user['role'] ?? 'admin';
        $_SESSION['admin_last_active'] = time();
        clear_failed_attempts($username);
        return ['ok' => true];
    } else {
        record_login_attempt($username, false);
        return ['ok' => false, 'error' => 'credentials'];
    }
}

function admin_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) init_session();

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => $params['secure'] ?? false,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Strict'
            ]);
        } else {
            setcookie(session_name(), '', time() - 42000, ($params['path'] ?? '/') . '; samesite=Strict', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
        }
    }
    session_unset();
    session_destroy();
}

function is_admin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) init_session();
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) return false;
    $timeout = 30 * 60;
    if (!empty($_SESSION['admin_last_active']) && (time() - (int)$_SESSION['admin_last_active'] > $timeout)) {
        admin_logout();
        return false;
    }
    $_SESSION['admin_last_active'] = time();
    $role = $_SESSION['admin_role'] ?? '';
    return $role === 'admin' || $role === 'superadmin';
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: ' . LOGIN_REDIRECT);
        exit;
    }
}

// --- Inicjalizacja modułu (bez efektów ubocznych poza tworzeniem tabel jeśli potrzeba) ---
init_session();
init_auth_tables();

// EOF
