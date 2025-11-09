<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_start();

define('DATA_DIR', __DIR__ . '/data');
define('MAX_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 15 * 60);
define('DB_FILE', DATA_DIR . '/auth.db');

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
        @file_put_contents(DATA_DIR . '/.htaccess', "Deny from all\n");
    }
}

function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function attempts_file(string $key): string
{
    return DATA_DIR . '/attempt_' . sha1($key) . '.json';
}

function read_attempts(string $key): array
{
    $f = attempts_file($key);
    if (!file_exists($f)) {
        return ['count' => 0, 'first' => 0, 'blocked_until' => 0];
    }

    $json = @file_get_contents($f);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['count' => 0, 'first' => 0, 'blocked_until' => 0];
    }

    return $data;
}

function write_attempts(string $key, array $data): void
{
    $f = attempts_file($key);
    file_put_contents($f, json_encode($data));
    @chmod($f, 0600);
}

function clear_attempts(string $key): void
{
    $f = attempts_file($key);
    if (file_exists($f)) {
        unlink($f);
    }
}

function register_failed_attempt(string $key): void
{
    $data = read_attempts($key);
    $now = time();

    if ($data['first'] === 0 || ($now - $data['first']) > LOCKOUT_SECONDS) {
        $data = ['count' => 1, 'first' => $now, 'blocked_until' => 0];
    } else {
        $data['count']++;
        if ($data['count'] >= MAX_ATTEMPTS) {
            $data['blocked_until'] = $now + LOCKOUT_SECONDS;
        }
    }

    write_attempts($key, $data);
}

function is_blocked(string $key): array
{
    $data = read_attempts($key);
    $now = time();

    if (!empty($data['blocked_until']) && $now < (int)$data['blocked_until']) {
        return ['blocked' => true, 'until' => (int)$data['blocked_until']];
    }

    return ['blocked' => false, 'until' => 0];
}

function get_pdo(): PDO
{
    ensure_data_dir();
    $dsn = 'sqlite:' . DB_FILE;
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL
    )");

    return $pdo;
}

function get_user_by_username(string $username): ?array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function create_admin(string $username, string $password): void
{
    $pdo = get_pdo();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:u, :p)');
    $stmt->execute([':u' => $username, ':p' => $hash]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

ensure_data_dir();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['logout']) && !empty($_SESSION['admin_id'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/'));
    exit;
}

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['logout'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');
    $ip = get_client_ip();

    if (!verify_csrf($token)) {
        $errors[] = 'CSRF token invalid. Please refresh the form.';
    } elseif ($username === '' || $password === '') {
        $errors[] = 'Please provide username and password.';
    } else {
        $ip_block = is_blocked('ip_' . $ip);
        if ($ip_block['blocked']) {
            $errors[] = 'Too many failed attempts from your IP. Try again after ' . date('Y-m-d H:i:s', $ip_block['until']) . '.';
        } else {
            $user_block = is_blocked('user_' . $username);
            if ($user_block['blocked']) {
                $errors[] = 'This account is temporarily locked due to failed attempts. Try again after ' . date('Y-m-d H:i:s', $user_block['until']) . '.';
            } else {
                $user = get_user_by_username($username);
                if (!$user) {
                    register_failed_attempt('ip_' . $ip);
                    register_failed_attempt('user_' . $username);
                    $errors[] = 'Invalid credentials.';
                } else {
                    if (password_verify($password, $user['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['admin_id'] = (int)$user['id'];
                        clear_attempts('ip_' . $ip);
                        clear_attempts('user_' . $username);
                        $messages[] = 'Login successful. You are now authenticated.';
                    } else {
                        register_failed_attempt('ip_' . $ip);
                        register_failed_attempt('user_' . $username);
                        $errors[] = 'Invalid credentials.';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,'Helvetica Neue',Arial;margin:2rem}
        .box{max-width:420px;padding:1.5rem;border:1px solid #ddd;border-radius:8px}
        .input{display:block;width:100%;padding:.5rem;margin:.5rem 0}
        .err{color:#900}
        .ok{color:#060}
    </style>
</head>
<body>
<div class="box">
    <h2>Logowanie</h2>

    <?php foreach ($errors as $e): ?>
        <div class="err"><?=htmlspecialchars($e, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $m): ?>
        <div class="ok"><?=htmlspecialchars($m, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')?></div>
    <?php endforeach; ?>

    <?php if (empty($_SESSION['admin_id'])): ?>
        <form method="post" action="<?=htmlspecialchars($_SERVER['PHP_SELF'] ?? '')?>">
            <label>Username<br>
                <input class="input" type="text" name="username" value="<?=htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES)?>" required>
            </label>

            <label>Password<br>
                <input class="input" type="password" name="password" required>
            </label>

            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token())?>">
            <button type="submit">Zaloguj</button>
        </form>
    <?php else: ?>
        <p>Jesteś zalogowany jako admin (ID: <?= (int)$_SESSION['admin_id'] ?>).</p>
        <form method="post" action="<?=htmlspecialchars($_SERVER['PHP_SELF'] ?? '')?>">
            <button name="logout" value="1">Wyloguj</button>
        </form>
    <?php endif; ?>

    <hr>
    <small>Uwaga: Ten skrypt używa SQLite i przechowuje pliki w katalogu <code>data/</code>. W środowisku produkcyjnym umieść dane poza katalogiem publicznym i użyj TLS.</small>
</div>
</body>
</html>
