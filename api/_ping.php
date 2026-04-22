<?php
// DIAG minimal — test chaîne require+db() étape par étape.
header('Content-Type: application/json; charset=utf-8');
$steps = [];
try { require_once __DIR__ . '/config.php'; $steps[] = 'config.php_loaded'; }
catch (Throwable $e) { echo json_encode(['step'=>'config','err'=>$e->getMessage()]); exit; }

try { require_once __DIR__ . '/db.php'; $steps[] = 'db.php_loaded'; }
catch (Throwable $e) { echo json_encode(['step'=>'db.php','err'=>$e->getMessage(),'steps'=>$steps]); exit; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $steps[] = 'pdo_connected';
} catch (Throwable $e) {
    echo json_encode(['step'=>'pdo','err'=>$e->getMessage(),'steps'=>$steps]);
    exit;
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $steps[] = 'schema_read';
} catch (Throwable $e) {
    echo json_encode(['step'=>'schema','err'=>$e->getMessage(),'steps'=>$steps]);
    exit;
}

$cols_trim = array_map(fn($c) => $c['Field'].':'.$c['Type'], $cols);

try {
    $existing = $pdo->query("SELECT id, email, role, active FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo json_encode(['step'=>'select','err'=>$e->getMessage(),'steps'=>$steps,'cols'=>$cols_trim]);
    exit;
}

echo json_encode([
    'ok' => true,
    'steps' => $steps,
    'users_cols' => $cols_trim,
    'existing_users' => $existing,
    'ip_seen' => $_SERVER['REMOTE_ADDR'] ?? '?',
]);
