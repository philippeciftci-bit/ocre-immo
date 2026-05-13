<?php
// M/2026/05/13/19 — Superadmin logs : superadmin_actions + auth_sessions recents.
require_once __DIR__ . '/superadmin_lib.php';
superadmin_or_403();
header('Content-Type: application/json');

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
$st = $meta->prepare("SELECT id, admin_email, action, target_type, target_id, details, ip_address, created_at FROM superadmin_actions ORDER BY id DESC LIMIT ?");
$st->bindValue(1, $limit, PDO::PARAM_INT);
$st->execute();
echo json_encode(['ok' => true, 'logs' => $st->fetchAll()]);
