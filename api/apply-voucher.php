<?php
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['code'])) {
    echo json_encode(['valid' => false, 'message' => 'Brak kodu.']);
    exit;
}

$code = trim(strtoupper($input['code']));

switch ($code) {
    case 'SAVE10':
        echo json_encode([
            'valid' => true,
            'code' => 'SAVE10',
            'type' => 'percent',
            'amount' => 10,
            'message' => 'Kupon SAVE10: 10% zniżki'
        ]);
        break;

    case 'FIX50':
        echo json_encode([
            'valid' => true,
            'code' => 'FIX50',
            'type' => 'fixed',
            'amount' => 50,
            'message' => 'Kupon FIX50: 50 zł zniżki'
        ]);
        break;

    case 'EXPIRED':
        echo json_encode(['valid' => false, 'message' => 'Kod wygasł.']);
        break;

    default:
        echo json_encode(['valid' => false, 'message' => 'Nieprawidłowy kod.']);
        break;
}

exit;
