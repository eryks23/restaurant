<?php
// Ustawienia połączenia z bazą danych
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'v');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4_polish_ci');

// Połączenie z bazą danych
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Sprawdzanie, czy formularz został wysłany
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $name = $_POST['name'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Walidacja danych
    if ($password !== $confirm_password) {
        die("Hasła się nie zgadzają.");
    }

    // Sprawdzanie, czy email jest poprawny
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Nieprawidłowy email.");
    }

    // Haszowanie hasła
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Przygotowanie zapytania SQL
    $stmt = $pdo->prepare("INSERT INTO users (email, name, password) VALUES (?, ?, ?)");
    $stmt->execute([$email, $name, $hashed_password]);

    echo "Rejestracja zakończona sukcesem!";
}
?>
