<?php
// M/2026/05/09/42 — M88 : supprime la subscription de l'agent connecté.
// POST {endpoint?}  — si endpoint manquant, supprime TOUTES les subscriptions de cet agent.
require_once __DIR__ . '/db.php';
setCorsHeaders();
$user = requireAuth();
$uid = (int) ($user['_origin_user_id'] ?? $user['id']);
$input = getInput();
$endpoint = trim((string) ($input['endpoint'] ?? ''));

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if ($endpoint !== '') {
    $st = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
    $st->execute([$uid, $endpoint]);
} else {
    $st = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
    $st->execute([$uid]);
}
echo json_encode(['ok' => true, 'removed' => $st->rowCount()]);
