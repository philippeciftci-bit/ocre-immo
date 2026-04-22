<?php
// URGENT — créer user ozkan44000@gmail.com (Ophélie test) pour Ocre v17.
// IP whitelist VPS atelier.
require_once __DIR__ . '/db.php';

$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden', 'seen_ip' => $remote_ip]));
}

header('Content-Type: application/json; charset=utf-8');

$out = [];
try {
    $pdo = db();

    $email = 'ozkan44000@gmail.com';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE users SET password_hash = 'PLACEHOLDER', role = 'agent', active = 1, prenom = ?, nom = ? WHERE id = ?")
            ->execute(['Ophélie', 'Test', $existing['id']]);
        $out['action'] = 'updated_existing';
        $out['user_id'] = (int)$existing['id'];
    } else {
        $pdo->prepare("INSERT INTO users (email, password_hash, role, prenom, nom, active, created_at) VALUES (?, 'PLACEHOLDER', 'agent', ?, ?, 1, NOW())")
            ->execute([$email, 'Ophélie', 'Test']);
        $out['action'] = 'created';
        $out['user_id'] = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT id, email, role, active FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $out['final'] = $stmt->fetch();

    $out['ok'] = true;
} catch (Exception $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
