<?php
// URGENT — créer user ozkan44000@gmail.com (Ophélie test) pour Ocre v17.
// IP whitelist VPS atelier, self-destruct après exécution.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    @unlink(__FILE__);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden', 'seen_ip' => $remote_ip]));
}

$out = [];

// Inspect schema users
try {
    $cols = db()->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $out['schema'] = array_map(fn($c) => $c['Field'] . ' ' . $c['Type'], $cols);
} catch (Exception $e) { $out['schema_err'] = $e->getMessage(); }

// List existing users
try {
    $rows = db()->query("SELECT id, email, role, active, LEFT(password_hash,12) AS hash12, created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $out['existing'] = $rows;
} catch (Exception $e) { $out['list_err'] = $e->getMessage(); }

// Create if absent
$email = 'ozkan44000@gmail.com';
$stmt = db()->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
$stmt->execute([$email]);
$existing = $stmt->fetch();
if ($existing) {
    $out['action'] = 'already_exists';
    $out['user_id'] = (int)$existing['id'];
    // Reset password_hash to PLACEHOLDER pour forcer set_password au 1er accès.
    db()->prepare("UPDATE users SET password_hash = 'PLACEHOLDER', role = 'agent', active = 1, prenom = 'Ophélie', nom = 'Test' WHERE id = ?")
        ->execute([$existing['id']]);
    $out['reset_to_placeholder'] = true;
} else {
    try {
        $stmt = db()->prepare(
            "INSERT INTO users (email, password_hash, role, prenom, nom, active, created_at)
             VALUES (?, 'PLACEHOLDER', 'agent', 'Ophélie', 'Test', 1, NOW())"
        );
        $stmt->execute([$email]);
        $out['action'] = 'created';
        $out['user_id'] = (int)db()->lastInsertId();
    } catch (Exception $e) {
        $out['create_err'] = $e->getMessage();
    }
}

// Final state
$stmt = db()->prepare("SELECT id, email, role, active, LEFT(password_hash,15) AS hash15 FROM users WHERE LOWER(email) = LOWER(?)");
$stmt->execute([$email]);
$out['final_state'] = $stmt->fetch(PDO::FETCH_ASSOC);

@unlink(__FILE__);
$out['self_destructed'] = true;
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
