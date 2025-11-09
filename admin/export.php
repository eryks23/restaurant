<?php

require_once __DIR__ . '/bootstrap.php';

requireAdmin();

$table = 'orders';
$columns = ['id', 'user_id', 'status', 'created_at', 'total'];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbDsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
$dateTo   = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;
$status   = isset($_GET['status']) ? trim($_GET['status']) : null;

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : null;

$MAX_PER_PAGE_CSV = 5000;
$MAX_PER_PAGE_PDF = 1000;
$MAX_TOTAL_CSV = 100000;
$MAX_TOTAL_PDF = 5000;

if ($format === 'pdf') {
    $perPage = $perPage ? min($perPage, $MAX_PER_PAGE_PDF) : 500;
    $maxTotal = $MAX_TOTAL_PDF;
} else {
    $perPage = $perPage ? min($perPage, $MAX_PER_PAGE_CSV) : 2000;
    $maxTotal = $MAX_TOTAL_CSV;
}

function validateDate($d) {
    if (!$d) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return $d;
    }
    return null;
}

$dateFrom = validateDate($dateFrom);
$dateTo = validateDate($dateTo);

$where = [];
$params = [];

if ($dateFrom) {
    $where[] = "created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo) {
    $where[] = "created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

if ($status !== null && $status !== '') {
    $where[] = "status = :status";
    $params[':status'] = $status;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) as cnt FROM `{$table}` {$whereSql}";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

if ($total > $maxTotal) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Zbyt dużo rekordów do eksportu. Zawęź filtry lub użyj stronicowania.',
        'total' => $total,
        'max_allowed' => $maxTotal,
    ]);
    exit;
}

$now = (new DateTime())->format('Ymd_His');
$filenameBase = "export_{$table}_{$now}";

@set_time_limit(300);

function fetchPage(PDO $pdo, $table, $columns, $whereSql, $params, $offset, $limit) {
    $cols = implode(', ', array_map(function($c){ return "`".str_replace('`','',$c)."`"; }, $columns));
    $sql = "SELECT {$cols} FROM `{$table}` {$whereSql} ORDER BY id ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        yield $row;
    }
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out === false) exit;

    echo "\xEF\xBB\xBF";

    fputcsv($out, $columns);

    $offset = ($page - 1) * $perPage;
    $rowsExported = 0;

    for ($currentOffset = $offset; $currentOffset < $total; $currentOffset += $perPage) {
        foreach (fetchPage($pdo, $table, $columns, $whereSql, $params, $currentOffset, $perPage) as $row) {
            $outRow = [];
            foreach ($columns as $c) $outRow[] = isset($row[$c]) ? $row[$c] : '';
            fputcsv($out, $outRow);
            $rowsExported++;
        }
        if (function_exists('ob_flush')) { ob_flush(); }
        if (function_exists('flush')) { flush(); }
    }

    fclose($out);
    exit;
}

if ($format === 'pdf') {
    if (class_exists('\TCPDF')) {
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Export script');
        $pdf->SetAuthor('Export');
        $pdf->SetTitle('Export');
        $pdf->SetMargins(10, 10, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $html = '<table border="1" cellpadding="4"><thead><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $offset = ($page - 1) * $perPage;
        $rowsPerFlush = 200;
        $bufferedRows = 0;
        $totalExported = 0;

        for ($currentOffset = $offset; $currentOffset < $total; $currentOffset += $perPage) {
            foreach (fetchPage($pdo, $table, $columns, $whereSql, $params, $currentOffset, $perPage) as $row) {
                $html .= '<tr>';
                foreach ($columns as $c) {
                    $val = isset($row[$c]) ? $row[$c] : '';
                    $html .= '<td>' . htmlspecialchars((string)$val) . '</td>';
                }
                $html .= '</tr>';
                $bufferedRows++;
                $totalExported++;

                if ($bufferedRows >= $rowsPerFlush) {
                    $html .= '</tbody></table>';
                    $pdf->writeHTML($html, true, false, true, false, '');
                    $html = '<table border="1" cellpadding="4"><thead><tr>';
                    foreach ($columns as $col) { $html .= '<th>' . htmlspecialchars($col) . '</th>'; }
                    $html .= '</tr></thead><tbody>';
                    $bufferedRows = 0;
                }
            }
        }

        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
        echo $pdf->Output('', 'S');
        exit;

    } elseif (class_exists('FPDF')) {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 10);

        foreach ($columns as $col) $pdf->Cell(40, 7, $col, 1);
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 9);

        $offset = ($page - 1) * $perPage;
        for ($currentOffset = $offset; $currentOffset < $total; $currentOffset += $perPage) {
            foreach (fetchPage($pdo, $table, $columns, $whereSql, $params, $currentOffset, $perPage) as $row) {
                foreach ($columns as $c) {
                    $pdf->Cell(40, 6, mb_strimwidth((string)($row[$c] ?? ''), 0, 40, '...'), 1);
                }
                $pdf->Ln();
            }
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
        $pdf->Output('D', $filenameBase . '.pdf');
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Brak biblioteki PDF. Zainstaluj tecnickcom/tcpdf lub FPDF.']);
        exit;
    }
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Nieobsługiwany format. Użyj format=csv lub format=pdf']);
exit;
