<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAdmin();

$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : null;
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : null;
$status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
$min_duration = isset($_GET['min_duration']) && $_GET['min_duration'] !== '' ? (int)$_GET['min_duration'] : null;
$max_duration = isset($_GET['max_duration']) && $_GET['max_duration'] !== '' ? (int)$_GET['max_duration'] : null;
$search = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;
$voucher = isset($_GET['voucher']) && $_GET['voucher'] !== '' ? trim($_GET['voucher']) : null;
$payment_status = isset($_GET['payment_status']) && $_GET['payment_status'] !== '' ? $_GET['payment_status'] : null;

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($from_date) {
    $where[] = 'start_date >= :from_date';
    $params[':from_date'] = $from_date;
}

if ($to_date) {
    $where[] = 'start_date <= :to_date';
    $params[':to_date'] = $to_date;
}

if ($status) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}

if ($min_duration !== null) {
    $where[] = 'DATEDIFF(end_date, start_date) >= :min_duration';
    $params[':min_duration'] = $min_duration;
}

if ($max_duration !== null) {
    $where[] = 'DATEDIFF(end_date, start_date) <= :max_duration';
    $params[':max_duration'] = $max_duration;
}

if ($search) {
    $where[] = '(customer_name LIKE :search OR customer_email LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($voucher) {
    $where[] = 'voucher_code = :voucher';
    $params[':voucher'] = $voucher;
}

if ($payment_status) {
    $where[] = 'payment_status = :payment_status';
    $params[':payment_status'] = $payment_status;
}

$where_sql = '';
if (count($where) > 0) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$stats_sql = "
    SELECT
        COUNT(*) AS total_bookings,
        COALESCE(SUM(total_amount), 0) AS revenue,
        COALESCE(SUM(discount_amount), 0) AS promotions
    FROM bookings
    $where_sql
";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$list_sql = "
    SELECT
        id,
        customer_name,
        customer_email,
        voucher_code,
        status,
        payment_status,
        start_date,
        end_date,
        total_amount,
        discount_amount,
        DATEDIFF(end_date, start_date) AS duration
    FROM bookings
    $where_sql
    ORDER BY start_date DESC
    LIMIT :limit OFFSET :offset
";
$list_stmt = $pdo->prepare($list_sql);

foreach ($params as $k => $v) {
    $list_stmt->bindValue($k, $v);
}

$list_stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$list_stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$list_stmt->execute();
$bookings = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

function build_query(array $overrides = []) {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }
    return http_build_query($base);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard rezerwacji</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h1 class="mb-4">Panel rezerwacji</h1>

    <form method="get" class="row g-3 mb-4">
        <div class="col-md-2">
            <label class="form-label">Od (data)</label>
            <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Do (data)</label>
            <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">-- dowolny --</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>pending</option>
                <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>confirmed</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>cancelled</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Płatność</label>
            <select name="payment_status" class="form-select">
                <option value="">-- dowolny --</option>
                <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>paid</option>
                <option value="unpaid" <?= $payment_status === 'unpaid' ? 'selected' : '' ?>>unpaid</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Min. czas (dni)</label>
            <input type="number" name="min_duration" class="form-control" value="<?= htmlspecialchars($min_duration) ?>" min="0">
        </div>

        <div class="col-md-2">
            <label class="form-label">Max. czas (dni)</label>
            <input type="number" name="max_duration" class="form-control" value="<?= htmlspecialchars($max_duration) ?>" min="0">
        </div>

        <div class="col-md-4">
            <label class="form-label">Szukaj (imię/email)</label>
            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Voucher</label>
            <input type="text" name="voucher" class="form-control" value="<?= htmlspecialchars($voucher) ?>">
        </div>

        <div class="col-md-5 d-flex align-items-end">
            <button type="submit" class="btn btn-primary me-2">Filtruj</button>
            <a href="dashboard.php" class="btn btn-secondary">Wyczyść</a>
        </div>
    </form>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card p-3">
                <h5>Liczba rezerwacji</h5>
                <p class="display-6"><?= (int)$stats['total_bookings'] ?></p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-3">
                <h5>Przychody (suma)</h5>
                <p class="display-6"><?= number_format((float)$stats['revenue'], 2) ?> zł</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-3">
                <h5>Promocje (suma rabatów)</h5>
                <p class="display-6"><?= number_format((float)$stats['promotions'], 2) ?> zł</p>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <?php $csv_q = build_query(['format' => 'csv']); ?>
        <?php $pdf_q = build_query(['format' => 'pdf']); ?>
        <a href="export.php?<?= $csv_q ?>" class="btn btn-outline-success me-2">Eksport CSV</a>
        <a href="export.php?<?= $pdf_q ?>" class="btn btn-outline-danger">Eksport PDF</a>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data start</th>
                    <th>Data koniec</th>
                    <th>Klient</th>
                    <th>Email</th>
                    <th>Duration (dni)</th>
                    <th>Voucher</th>
                    <th>Status</th>
                    <th>Płatność</th>
                    <th>Kwota</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($bookings) === 0): ?>
                    <tr><td colspan="10">Brak rezerwacji dla zadanych filtrów.</td></tr>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td><a href="booking-view.php?id=<?= urlencode($b['id']) ?>"><?= htmlspecialchars($b['id']) ?></a></td>
                            <td><?= htmlspecialchars($b['start_date']) ?></td>
                            <td><?= htmlspecialchars($b['end_date']) ?></td>
                            <td><?= htmlspecialchars($b['customer_name']) ?></td>
                            <td><?= htmlspecialchars($b['customer_email']) ?></td>
                            <td><?= htmlspecialchars($b['duration']) ?></td>
                            <td><?= htmlspecialchars($b['voucher_code']) ?></td>
                            <td><?= htmlspecialchars($b['status']) ?></td>
                            <td><?= htmlspecialchars($b['payment_status']) ?></td>
                            <td><?= number_format((float)$b['total_amount'], 2) ?> zł</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <nav aria-label="Stronicowanie">
        <ul class="pagination">
            <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?= build_query(['page' => $page-1]) ?>">&laquo; Poprzednia</a></li>
            <?php endif; ?>
            <li class="page-item active"><span class="page-link"><?= $page ?></span></li>
            <?php if (count($bookings) === $limit): ?>
                <li class="page-item"><a class="page-link" href="?<?= build_query(['page' => $page+1]) ?>">Następna &raquo;</a></li>
            <?php endif; ?>
        </ul>
    </nav>

</div>
</body>
</html>