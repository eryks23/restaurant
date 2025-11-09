<?php
declare(strict_types=1);

/**
 * includes/db_connect.php
 *
 * Bezpieczne połączenie z MySQL przez mysqli.
 * Automatycznie ładuje konfigurację z pliku .env w katalogu głównym projektu.
 */

function db_load_env(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // pomiń komentarze
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (!isset($_ENV[$name]) && !getenv($name)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// automatycznie załaduj .env z katalogu nadrzędnego
db_load_env(__DIR__ . '/../.env');

function db_env(string $name, $default = null): ?string {
    $v = getenv($name);
    if ($v !== false) return $v;
    if (isset($_ENV[$name])) return (string)$_ENV[$name];
    if (isset($_SERVER[$name])) return (string)$_SERVER[$name];
    return $default === null ? null : (string)$default;
}

function db_log(string $msg): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $file = $logDir . '/db_connect.log';
    $time = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
}

function db_connect(): mysqli {
    $host = db_env('DB_HOST', '127.0.0.1');
    $user = db_env('DB_USER', 'root');
    $pass = db_env('DB_PASS', '');
    $db   = db_env('DB_NAME', 'v');
    $port = (int) db_env('DB_PORT', 3306);
    $charset = db_env('DB_CHARSET', 'utf8mb4');

    db_log("Connecting to {$host}:{$port}, db={$db}, user={$user}");

    $mysqli = @new mysqli($host, $user, $pass, $db, $port);

    if ($mysqli->connect_errno) {
        $msg = "DB connection failed: ({$mysqli->connect_errno}) {$mysqli->connect_error}";
        db_log($msg);
        throw new RuntimeException('Błąd połączenia z bazą danych: ' . $mysqli->connect_error);
    }

    // jeżeli charset ma collation (np. utf8mb4_polish_ci) — weź tylko kodowanie
    if (str_contains($charset, '_')) {
        $charset = substr($charset, 0, strpos($charset, '_'));
    }

    if (!$mysqli->set_charset($charset)) {
        db_log("Warning: cannot set charset {$charset}: {$mysqli->error}");
    }

    db_log("Połączono z bazą danych pomyślnie.");
    return $mysqli;
}
