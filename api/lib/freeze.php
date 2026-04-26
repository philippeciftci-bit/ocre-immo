<?php
// V20 phase 8 — helper freeze : vérifie si user a accès écriture au workspace selon état rupture.
require_once __DIR__ . '/router.php';

function freeze_state(int $wsc_id): ?array {
    $stmt = pdo_meta()->prepare(
        "SELECT id, requester_user_id, requested_at, scheduled_for, cancelled_at, executed_at
         FROM rupture_requests
         WHERE wsc_id = ? AND cancelled_at IS NULL AND executed_at IS NULL
         ORDER BY requested_at DESC LIMIT 1"
    );
    $stmt->execute([$wsc_id]);
    return $stmt->fetch() ?: null;
}

function is_dossier_frozen_for(int $wsc_id, int $user_id, int $dossier_apporteur_id): bool {
    $r = freeze_state($wsc_id);
    if (!$r) return false;
    if ((int)$r['requester_user_id'] === $user_id) {
        return $dossier_apporteur_id !== $user_id;
    }
    return $dossier_apporteur_id === (int)$r['requester_user_id'];
}

function audit_blocked_attempt(int $wsc_id, int $user_id, int $dossier_id, int $apporteur_id, string $action): void {
    $stmt = pdo_meta()->prepare(
        "INSERT INTO audit_log (workspace_id, actor_user_id, action, target_type, target_id, payload_json, created_at)
         VALUES (?, ?, ?, 'dossier', ?, ?, NOW())"
    );
    $stmt->execute([
        $wsc_id, $user_id, 'BLOCKED_' . $action, $dossier_id,
        json_encode(['apporteur_id' => $apporteur_id, 'reason' => 'freeze_rupture'])
    ]);
    $notif = pdo_meta()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, payload_json, created_at)
         VALUES (?, 'freeze_violation', ?, ?, ?, NOW())"
    );
    $title = "Tentative de modification bloquée sur ton dossier";
    $body = "Une tentative d'édition pendant le freeze de rupture a été bloquée et tracée dans l'audit log.";
    $notif->execute([$apporteur_id, $title, $body, json_encode(['dossier_id' => $dossier_id, 'wsc_id' => $wsc_id])]);
}
