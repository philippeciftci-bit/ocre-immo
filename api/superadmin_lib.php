<?php
// M/2026/05/13/19 — Superadmin M96 : auth guard whitelist email.
// V1 : verifie session user normale + email whitelist /etc/ocre/superadmin_emails.
// V2 reporte : IP whitelist + session courte 30min idle.
require_once __DIR__ . '/db.php';

function superadmin_or_403(): array {
    $u = currentUser();
    if (!$u) { http_response_code(401); header('Content-Type:application/json'); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
    $whitelistPath = '/etc/ocre/superadmin_emails';
    $allowed = [];
    if (is_readable($whitelistPath)) {
        foreach (preg_split('/\r?\n/', (string)@file_get_contents($whitelistPath)) as $line) {
            $line = trim($line);
            if ($line && $line[0] !== '#') $allowed[] = strtolower($line);
        }
    }
    if (!in_array(strtolower($u['email'] ?? ''), $allowed, true)) {
        http_response_code(403); header('Content-Type:application/json');
        echo json_encode(['ok'=>false,'error'=>'not_superadmin']); exit;
    }
    return $u;
}

function superadmin_log(string $action, ?string $targetType = null, ?string $targetId = null, $details = null): void {
    try {
        $u = currentUser();
        if (!$u) return;
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $meta->prepare("INSERT INTO superadmin_actions (admin_email, action, target_type, target_id, details, ip_address) VALUES (?,?,?,?,?,?)");
        $st->execute([
            $u['email'] ?? '',
            $action,
            $targetType,
            $targetId,
            $details ? (is_string($details) ? $details : json_encode($details)) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $e) {}
}
