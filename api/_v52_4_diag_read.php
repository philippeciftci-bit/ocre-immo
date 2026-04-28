<?php
// V52.4.3 — lecture du diag log + recent sessions (IP-whitelist VPS atelier).
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAG LOG (last 50) ===\n";
$path = __DIR__ . '/_diag.log';
echo "log_path=$path  exists=" . (file_exists($path) ? 'yes' : 'no') . "  writable_dir=" . (is_writable(__DIR__) ? 'yes' : 'no') . "\n";
// Test write
$test = @file_put_contents($path . '.test', 'probe ' . date('c') . "\n", FILE_APPEND | LOCK_EX);
echo "test_write_bytes=" . var_export($test, true) . "\n";
if (!file_exists($path)) {
    echo "(no log file yet — Philippe doit refresh /app/ d'abord)\n";
} else {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    $tail = array_slice($lines ?: [], -50);
    foreach ($tail as $l) echo $l . "\n";
}

echo "\n=== SESSIONS RECENTES (30 min) ===\n";
try {
    $stmt = db()->prepare(
        "SELECT s.user_id, u.email, s.created_at, s.expires_at, s.ip, LEFT(s.token,16) as token_start
         FROM sessions s LEFT JOIN users u ON u.id = s.user_id
         WHERE s.created_at > NOW() - INTERVAL 30 MINUTE
         ORDER BY s.created_at DESC LIMIT 20"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $r) {
        echo sprintf("user_id=%s email=%s created=%s ip=%s token=%s...\n",
            $r['user_id'], $r['email'], $r['created_at'], $r['ip'], $r['token_start']);
    }
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}

echo "\n=== ALL CLIENTS user_id IN (1,2) ===\n";
try {
    $stmt = db()->prepare(
        "SELECT id, user_id, prenom, nom, projet, is_draft, is_staged, archived, deleted_at,
                JSON_EXTRACT(data,'$.is_demo') as is_demo
         FROM clients WHERE user_id IN (1,2) ORDER BY user_id, id"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $r) {
        echo sprintf("id=%d user_id=%d prenom=%s nom=%s projet=%s draft=%s staged=%s arch=%s deleted=%s is_demo=%s\n",
            $r['id'], $r['user_id'], $r['prenom'] ?? '', $r['nom'] ?? '',
            $r['projet'] ?? '', $r['is_draft'], $r['is_staged'], $r['archived'],
            $r['deleted_at'] ?? 'null', $r['is_demo']);
    }
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
