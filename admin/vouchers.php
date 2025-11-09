<?php
require_once __DIR__ . '/init.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    $code = trim($_POST['code'] ?? '');
    $type = $_POST['type'] ?? 'percent';
    $value = $_POST['value'] ?? '';
    $valid_from = $_POST['valid_from'] ?? '';
    $valid_to = $_POST['valid_to'] ?? '';
    $usage_limit = $_POST['usage_limit'] ?? null;
    $per_user_limit = $_POST['per_user_limit'] ?? null;

    if ($code === '') {
        $errors[] = 'Code is required.';
    }

    if (!in_array($type, ['percent', 'fixed'], true)) {
        $errors[] = 'Invalid type.';
    }

    if ($value === '' || !is_numeric($value) || $value <= 0) {
        $errors[] = 'Value must be a number greater than 0.';
    }

    $parseLocal = function ($s) {
        if ($s === '') {
            return null;
        }
        $s = str_replace('T', ' ', $s);
        $dt = date_create($s);
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    };

    $vf = $parseLocal($valid_from);
    $vt = $parseLocal($valid_to);

    if ($valid_from && !$vf) {
        $errors[] = 'Invalid valid_from date.';
    }

    if ($valid_to && !$vt) {
        $errors[] = 'Invalid valid_to date.';
    }

    $usage_limit = ($usage_limit === '' ? null : (int)$usage_limit);
    $per_user_limit = ($per_user_limit === '' ? null : (int)$per_user_limit);

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO vouchers (code, type, value, valid_from, valid_to, usage_limit, per_user_limit)
                 VALUES (:code, :type, :value, :valid_from, :valid_to, :usage_limit, :per_user_limit)"
            );
            $stmt->execute([
                ':code' => $code,
                ':type' => $type,
                ':value' => number_format((float)$value, 2, '.', ''),
                ':valid_from' => $vf,
                ':valid_to' => $vt,
                ':usage_limit' => $usage_limit,
                ':per_user_limit' => $per_user_limit,
            ]);
            $success = 'Voucher created successfully.';
        } catch (PDOException $e) {
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
                $errors[] = 'Voucher code already exists.';
            } else {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$vouchers = [];
try {
    $q = $pdo->query(
        "SELECT v.*, (SELECT COUNT(*) FROM voucher_usages vu WHERE vu.voucher_id = v.id) AS usage_count
         FROM vouchers v
         ORDER BY v.created_at DESC"
    );
    $vouchers = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vouchers = [];
}

$view_voucher = null;
$usages = [];

if (isset($_GET['view']) && ctype_digit((string)$_GET['view'])) {
    $vid = (int)$_GET['view'];
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = :id');
    $stmt->execute([':id' => $vid]);
    $view_voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($view_voucher) {
        $hasUsers = false;
        try {
            $r = $pdo->query("SHOW TABLES LIKE 'users'");
            $hasUsers = (bool)$r->fetch();
        } catch (Exception $e) {
            $hasUsers = false;
        }

        if ($hasUsers) {
            $stmt = $pdo->prepare(
                'SELECT vu.*, u.email AS user_email
                 FROM voucher_usages vu
                 LEFT JOIN users u ON vu.user_id = u.id
                 WHERE vu.voucher_id = :vid
                 ORDER BY vu.created_at DESC
                 LIMIT 500'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT vu.*
                 FROM voucher_usages vu
                 WHERE vu.voucher_id = :vid
                 ORDER BY vu.created_at DESC
                 LIMIT 500'
            );
        }
        $stmt->execute([':vid' => $vid]);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vouchers admin</title>
<style>
body{font-family:system-ui, -apple-system, 'Segoe UI', Roboto, Arial; padding:20px;}
.form-row{margin-bottom:8px}
label{display:block;font-weight:600}
.input-inline{display:inline-block;margin-right:8px}
.table{border-collapse:collapse;width:100%;margin-top:12px}
.table th,.table td{border:1px solid #ddd;padding:8px}
.bad{color:#900}
.ok{color:#090}
.small{font-size:0.9em;color:#666}
</style>
</head>
<body>
<h1>Kody rabatowe</h1>

<?php if ($errors): ?>
    <div class="bad">
        <ul>
        <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="ok"><?= h($success) ?></div>
<?php endif; ?>

<h2>Utwórz nowy kod</h2>

<form method="post">
    <div class="form-row">
        <label>Code
            <input name="code" required placeholder="SUMMER2025" value="<?= h($_POST['code'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <label>Type
            <select name="type">
                <option value="percent" <?= isset($_POST['type']) && $_POST['type'] === 'percent' ? 'selected' : '' ?>>percent (%)</option>
                <option value="fixed" <?= isset($_POST['type']) && $_POST['type'] === 'fixed' ? 'selected' : '' ?>>fixed (currency)</option>
            </select>
        </label>
    </div>

    <div class="form-row">
        <label>Value
            <input name="value" type="number" step="0.01" required value="<?= h($_POST['value'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <label>Valid from
            <input name="valid_from" type="datetime-local" value="<?= h($_POST['valid_from'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <label>Valid to
            <input name="valid_to" type="datetime-local" value="<?= h($_POST['valid_to'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <label>Usage limit (total, leave empty for unlimited)
            <input name="usage_limit" type="number" min="0" value="<?= h($_POST['usage_limit'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <label>Per user limit (leave empty for unlimited)
            <input name="per_user_limit" type="number" min="0" value="<?= h($_POST['per_user_limit'] ?? '') ?>">
        </label>
    </div>

    <div class="form-row">
        <button name="create_voucher" type="submit">Utwórz</button>
    </div>
</form>

<h2>Lista kodów</h2>

<?php if (!$vouchers): ?>
    <p class="small">Brak kodów lub tabela nie istnieje.</p>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Code</th>
                <th>Type</th>
                <th>Value</th>
                <th>Valid from</th>
                <th>Valid to</th>
                <th>Usage limit</th>
                <th>Per user</th>
                <th>Used</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($vouchers as $v): ?>
            <tr>
                <td><?= h($v['id']) ?></td>
                <td><?= h($v['code']) ?></td>
                <td><?= h($v['type']) ?></td>
                <td><?= h($v['value']) ?></td>
                <td class="small"><?= h($v['valid_from']) ?></td>
                <td class="small"><?= h($v['valid_to']) ?></td>
                <td><?= is_null($v['usage_limit']) ? '∞' : h($v['usage_limit']) ?></td>
                <td><?= is_null($v['per_user_limit']) ? '∞' : h($v['per_user_limit']) ?></td>
                <td><?= h($v['usage_count']) ?></td>
                <td><a href="?view=<?= h($v['id']) ?>">Pokaż użycia</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($view_voucher): ?>
    <h2>Użycia dla: <?= h($view_voucher['code']) ?></h2>
    <p class="small">ID: <?= h($view_voucher['id']) ?> | Typ: <?= h($view_voucher['type']) ?> | Wartość: <?= h($view_voucher['value']) ?></p>

    <?php if (empty($usages)): ?>
        <p class="small">Brak użyć.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>user_id</th>
                    <th>user email</th>
                    <th>order_id</th>
                    <th>applied_value</th>
                    <th>ip</th>
                    <th>meta</th>
                    <th>created_at</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usages as $u): ?>
                <tr>
                    <td><?= h($u['id']) ?></td>
                    <td><?= h($u['user_id'] ?? '') ?></td>
                    <td><?= h($u['user_email'] ?? '') ?></td>
                    <td><?= h($u['order_id'] ?? '') ?></td>
                    <td><?= h($u['applied_value'] ?? '') ?></td>
                    <td><?= h($u['ip'] ?? '') ?></td>
                    <td><pre style="white-space:pre-wrap;max-width:400px"><?= h($u['meta'] ?? '') ?></pre></td>
                    <td class="small"><?= h($u['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><a href="vouchers.php">Powrót do listy</a></p>
<?php endif; ?>

</body>
</html>
