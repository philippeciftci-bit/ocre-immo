<?php
// M/2026/05/11/28 — Audit super-admin centralisé sur ocre_meta (DB préservée par reset_total).
// Découple la fonction audit_log_insert() legacy qui utilise db() (ocre_wsp_<slug> qui peut être DROP).
// Utiliser sa_audit_meta($userId, $action, $payload) dans tous les endpoints super-admin.

require_once __DIR__ . '/../db.php';

function sa_audit_meta(int $userId, string $action, array $payload = []): void {
    try {
        $pdo = pdo_meta();
        $st = $pdo->prepare("INSERT INTO audit_logs (user_id, action, payload, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $userId,
            $action,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256),
        ]);
    } catch (Throwable $e) { error_log('sa_audit_meta failed: ' . $e->getMessage()); }
}
