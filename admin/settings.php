<?php
require_once __DIR__ . '/init.php';
requireAdmin();

$use_db = false;
$pdo = null;

$outsideConfigDir = realpath(__DIR__ . '/../config') ?: __DIR__ . '/../config';
$uploadsDir = realpath(__DIR__ . '/../storage/uploads') ?: __DIR__ . '/../storage/uploads';

if (!is_dir($outsideConfigDir)) {
    @mkdir($outsideConfigDir, 0750, true);
}
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0750, true);
}

$settingsFile = $outsideConfigDir . '/settings.json';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = null;

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function validate_url($u) {
    if (filter_var($u, FILTER_VALIDATE_URL) === false) return false;
    return true;
}

function is_valid_image_upload($file) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return false;
    if ($file['size'] > 5 * 1024 * 1024) return false;
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($info['mime'], $allowed, true)) return false;
    return true;
}

$values = [
    'P24_POS_ID' => '',
    'P24_SIGN' => '',
    'P24_API_URL' => '',
    'admin_email' => '',
    'currency' => '',
    'site_name' => '',
    'base_url' => '',
    'logo' => '',
    'hero_image' => ''
];

if (file_exists($settingsFile)) {
    $json = @file_get_contents($settingsFile);
    $cur = @json_decode($json, true);
    if (is_array($cur)) {
        $values = array_merge($values, $cur);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Błąd CSRF.';
    }

    $P24_POS_ID = trim($_POST['P24_POS_ID'] ?? '');
    $P24_SIGN = trim($_POST['P24_SIGN'] ?? '');
    $P24_API_URL = trim($_POST['P24_API_URL'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    $site_name = trim($_POST['site_name'] ?? '');
    $base_url = trim($_POST['base_url'] ?? '');

    if ($P24_POS_ID === '') $errors[] = 'POS_ID jest wymagany.';
    if (!preg_match('/^[0-9]+$/', $P24_POS_ID)) $errors[] = 'POS_ID powinien zawierać tylko cyfry.';

    if ($P24_SIGN === '') $errors[] = 'P24_SIGN jest wymagany.';

    if ($P24_API_URL === '' || !validate_url($P24_API_URL)) $errors[] = 'P24_API_URL musi być poprawnym URL.';

    if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Proszę podać poprawny email administratora.';

    if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = 'Waluta powinna być kodem ISO 3-literowym (np. PLN, USD).';

    if ($site_name === '') $errors[] = 'Nazwa serwisu jest wymagana.';

    if ($base_url === '' || !validate_url($base_url)) $errors[] = 'Base URL musi być poprawnym URL.';

    $logoPath = $values['logo'] ?? '';
    $heroPath = $values['hero_image'] ?? '';

    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!is_valid_image_upload($_FILES['logo'])) {
            $errors[] = 'Logo: niepoprawny plik (max 5MB, jpg/png/gif/webp).';
        } else {
            $ext = image_type_to_extension(getimagesize($_FILES['logo']['tmp_name'])[2]);
            $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $uploadsDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $errors[] = 'Nie udało się zapisać pliku logo.';
            } else {
                @chmod($dest, 0640);
                $logoPath = $dest;
            }
        }
    }

    if (!empty($_FILES['hero_image']) && $_FILES['hero_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!is_valid_image_upload($_FILES['hero_image'])) {
            $errors[] = 'Hero image: niepoprawny plik (max 5MB, jpg/png/gif/webp).';
        } else {
            $ext = image_type_to_extension(getimagesize($_FILES['hero_image']['tmp_name'])[2]);
            $filename = 'hero_' . time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $uploadsDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['hero_image']['tmp_name'], $dest)) {
                $errors[] = 'Nie udało się zapisać pliku hero image.';
            } else {
                @chmod($dest, 0640);
                $heroPath = $dest;
            }
        }
    }

    if (empty($errors)) {
        $newSettings = [
            'P24_POS_ID' => $P24_POS_ID,
            'P24_SIGN' => $P24_SIGN,
            'P24_API_URL' => $P24_API_URL,
            'admin_email' => $admin_email,
            'currency' => $currency,
            'site_name' => $site_name,
            'base_url' => $base_url,
            'logo' => $logoPath,
            'hero_image' => $heroPath,
            'updated_at' => date(DATE_ATOM)
        ];

        if ($use_db && $pdo instanceof PDO) {
            try {
                $pdo->beginTransaction();
                $stmtGet = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :k');
                $stmtIns = $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (:k,:v)');
                $stmtUpd = $pdo->prepare('UPDATE settings SET `value` = :v WHERE `key` = :k');
                foreach ($newSettings as $k => $v) {
                    $stmtGet->execute([':k' => $k]);
                    if ($stmtGet->fetchColumn() > 0) {
                        $stmtUpd->execute([':k' => $k, ':v' => is_scalar($v) ? $v : json_encode($v)]);
                    } else {
                        $stmtIns->execute([':k' => $k, ':v' => is_scalar($v) ? $v : json_encode($v)]);
                    }
                }
                $pdo->commit();
                $success = 'Ustawienia zapisane w bazie danych.';
                $values = array_merge($values, $newSettings);
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Błąd zapisu do bazy danych: ' . $ex->getMessage();
            }
        } else {
            $saved = @file_put_contents($settingsFile, json_encode($newSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($saved === false) {
                $errors[] = 'Nie udało się zapisać pliku konfiguracyjnego: ' . $settingsFile;
            } else {
                @chmod($settingsFile, 0640);
                $success = 'Ustawienia zapisane do pliku konfiguracyjnego poza repo.';
                $values = array_merge($values, $newSettings);

                $ht = $outsideConfigDir . '/.htaccess';
                if (!file_exists($ht)) {
                    @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
                }
            }
        }
    }
}
?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ustawienia serwisu</title>
    <style>
        body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:20px auto;padding:10px}
        label{display:block;margin-top:10px}
        input[type=text],input[type=url],input[type=email],select{width:100%;padding:8px}
        input[type=file]{padding:6px}
        .preview{max-height:80px;border:1px solid #ddd;padding:4px;margin-top:6px}
    </style>
</head>
<body>
    <h1>Ustawienia serwisu (administrator)</h1>

    <?php if (!empty($errors)): ?>
        <div style="background:#ffecec;border:1px solid #ff9a9a;padding:10px;margin-bottom:10px">
            <strong>Wystąpiły błędy:</strong>
            <ul><?php foreach($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background:#e6ffea;border:1px solid #a0e0b6;padding:10px;margin-bottom:10px">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <label>Przelewy24 POS_ID
            <input type="text" name="P24_POS_ID" value="<?= e($values['P24_POS_ID']) ?>" required>
        </label>

        <label>P24_SIGN
            <input type="text" name="P24_SIGN" value="<?= e($values['P24_SIGN']) ?>" required>
        </label>

        <label>P24_API_URL
            <input type="url" name="P24_API_URL" value="<?= e($values['P24_API_URL']) ?>" required>
        </label>

        <label>Email administratora
            <input type="email" name="admin_email" value="<?= e($values['admin_email']) ?>" required>
        </label>

        <label>Waluta (kod ISO, np. PLN)
            <input type="text" name="currency" value="<?= e($values['currency']) ?>" maxlength="3" required>
        </label>

        <label>Nazwa strony
            <input type="text" name="site_name" value="<?= e($values['site_name']) ?>" required>
        </label>

        <label>Base URL
            <input type="url" name="base_url" value="<?= e($values['base_url']) ?>" required>
        </label>

        <label>Logo (jpg/png/gif/webp, max 5MB)
            <input type="file" name="logo" accept="image/*">
            <?php if (!empty($values['logo']) && file_exists($values['logo'])): ?>
                <div>Aktualne logo:</div>
                <img src="<?= e(str_replace($_SERVER['DOCUMENT_ROOT'], '', $values['logo'])) ?>" class="preview" alt="logo">
            <?php endif; ?>
        </label>

        <label>Hero image (jpg/png/gif/webp, max 5MB)
            <input type="file" name="hero_image" accept="image/*">
            <?php if (!empty($values['hero_image']) && file_exists($values['hero_image'])): ?>
                <div>Aktualny hero image:</div>
                <img src="<?= e(str_replace($_SERVER['DOCUMENT_ROOT'], '', $values['hero_image'])) ?>" class="preview" alt="hero">
            <?php endif; ?>
        </label>

        <p><button type="submit">Zapisz ustawienia</button></p>
    </form>

    <hr>
    <h3>Informacje dodatkowe</h3>
    <ul>
        <li>Plik konfiguracyjny (jeżeli używany): <code><?= e($settingsFile) ?></code></li>
        <li>Folder uploadów: <code><?= e($uploadsDir) ?></code></li>
        <li>Jeśli chcesz zapisywać do bazy danych ustaw <code>$use_db = true</code> i skonfiguruj PDO w górnej części pliku.</li>
        <li>Pamiętaj aby katalog <code><?= e($outsideConfigDir) ?></code> był poza repo i był odpowiednio zabezpieczony.</li>
    </ul>
</body>
</html>
