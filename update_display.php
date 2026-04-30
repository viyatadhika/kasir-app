<?php
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    $data = [
        'items' => [],
        'subtotal' => 0,
        'diskon' => 0,
        'total' => 0,
        'member' => null
    ];
}

$data['updated_at'] = date('Y-m-d H:i:s');

$file = __DIR__ . '/customer_display.json';
$ok = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

echo json_encode([
    'success' => $ok !== false,
    'file' => basename($file)
]);
