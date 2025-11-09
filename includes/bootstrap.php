<?php
// bootstrap.php
// Punkt wejścia dla endpointów API / admin / p24
// Umieść ten plik w katalogu projektu i na początku każdego publicznego skryptu wywołuj:
// require_once __DIR__ . '/bootstrap.php';
// (dostosuj ścieżkę jeśli umieścisz w includes/)

// Bezpośredni dostęp CLI jest ok - nie blokujemy
declare(strict_types=1);

// BASE_PATH = katalog nadrzędny względem bootstrap (zazwyczaj root projektu)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

/**
 * Bezpieczne require_once — jeśli plik obowiązkowy nie istnieje, rzuca RuntimeException.
 * Jeśli optional = true -> include_once jeśli istnieje, inaczej noop.
 */
function safe_require_once(string $relativePath, bool $optional = false): void
{
    $full = BASE_PATH . '/' . ltrim($relativePath, '/');
    if ($optional) {
        if (is_file($full) && is_readable($full)) {
            require_once $full;
        }
        return;
    }
    if (!is_file($full) || !is_readable($full)) {
        // FATAL: brak krytycznego pliku
        throw new RuntimeException("Missing required file: {$relativePath} (checked: {$full})");
    }
    require_once $full;
}

// 1) CONFIG (ustawia .env, timezone, cookie params; powinien też uruchomić sesję)
safe_require_once('config.php', false);

// 2) Composer autoload (opcjonalnie)
safe_require_once('vendor/autoload.php', true);

// 3) Core libs (db_connect MUST be single source of get_db())
safe_require_once('db_connect.php', false);
safe_require_once('functions.php', false);
safe_require_once('auth.php', false);
safe_require_once('consent.php', false);
safe_require_once('security.php', false);

// 4) Optional helpers (mailer, smtp, webhook validator, pdf)
safe_require_once('mailer.php', true);
safe_require_once('smtp.php', true);
safe_require_once('webhook_validator.php', true);
safe_require_once('pdf_helper.php', true);

// 5) Bezpieczne nagłówki dla requestów HTTP (jeśli funkcja jest dostępna)
if (php_sapi_name() !== 'cli' && function_exists('secure_headers')) {
    try {
        secure_headers();
    } catch (Throwable $e) {
        // Nie przerywamy działania aplikacji - logujemy i kontynuujemy
        error_log('secure_headers error: ' . $e->getMessage());
    }
}

// 6) Prosty handler wyjątków dla API: logujemy i zwracamy bezpieczny JSON jeśli to zapytanie HTTP
set_exception_handler(function (Throwable $e) {
    // Loguj szczegóły (do error_log lub pliku z logami ustawionych w config.php)
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Dodatkowo log trace w debug/dev
    if (defined('APP_ENV') && APP_ENV === 'development') {
        error_log($e->getTraceAsString());
    }

    if (php_sapi_name() !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = ['error' => 'server_error', 'message' => 'Internal server error'];
        // W dev możesz dołączyć więcej info; w prod trzymaj ogólnie
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $payload['detail'] = $e->getMessage();
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        // CLI - wypisz krótko
        fwrite(STDERR, "Uncaught exception: " . $e->getMessage() . PHP_EOL);
    }
    exit(1);
});

// 7) Ustaw handler błędów (konwersja warnings/notice do Exception w dev)
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    // Respektuj @ operator
    if (error_reporting() === 0) {
        return false;
    }
    // Rzucamy ErrorException aby przechwycić w exception handlerze
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 8) Helper debug/info — sprawdź połączenie do DB (opcjonalnie, tylko w dev)
if (defined('APP_ENV') && APP_ENV === 'development') {
    try {
        $pdo = get_db();
        // opcjonalny ping
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        error_log('DB connection test failed: ' . $e->getMessage());
        // Nie kończymy execution — wyżej exception handler ujawni błąd w bezpieczny sposób
    }
}

// 9) Gotowe — bootstrap załadowany
// Możesz dodać tu dowolne globalne inicjalizacje specyficzne dla projektu.
// Przykład użycia w endpointach:
// require_once __DIR__ . '/bootstrap.php';
// // teraz używasz: get_db(), generate_csrf_token(), is_admin(), send_email(), itp.

return; // bezpieczeństwo — zapobiega przypadkowemu echo/return wartości
