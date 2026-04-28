<?php
// M/2026/04/28/47 — EditConsent : workflow validation collaborative dossiers WSc.
// Phase 1 backend : tables dossier_edits + dossier_versions + endpoint
// create_edit / list_pending / decide.
// M/2026/04/28/51 — notify_edit_event multi-canal (in-app + Telegram inline + email log).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify_edit_event.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) $user['id'];

function ensureEditConsentSchema() {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_edits (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            workspace_id INT UNSIGNED NULL,
            author_user_id INT UNSIGNED NOT NULL,
            status ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
            changes LONGTEXT NOT NULL,
            parent_edit_id BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decided_by_user_id INT UNSIGNED NULL,
            decision_type ENUM('approve','modify','reject') NULL,
            decision_comment TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_status (client_id, status),
            INDEX idx_workspace_pending (workspace_id, status),
            INDEX idx_author (author_user_id, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_versions (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            version_num INT UNSIGNED NOT NULL,
            snapshot LONGTEXT NOT NULL,
            approved_by_edit_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_client_version (client_id, version_num),
            INDEX idx_client_latest (client_id, version_num)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}

ensureEditConsentSchema();

switch ($action) {

case 'create_edit': {
    $clientId = (int) ($input['client_id'] ?? 0);
    $changes = $input['changes'] ?? null;
    if (!$clientId || !is_array($changes)) jsonError('client_id et changes (array) requis', 400);
    $cur = db()->prepare("SELECT id, user_id FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $cur->execute([$clientId]);
    $cli = $cur->fetch();
    if (!$cli) jsonError('Dossier introuvable', 404);
    $stmt = db()->prepare(
        "INSERT INTO dossier_edits (client_id, author_user_id, status, changes, parent_edit_id)
         VALUES (?, ?, 'pending', ?, ?)"
    );
    $stmt->execute([$clientId, $uid,
        json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $input['parent_edit_id'] ?? null]);
    // Si parent_edit_id, marquer le parent superseded.
    if (!empty($input['parent_edit_id'])) {
        db()->prepare("UPDATE dossier_edits SET status = 'superseded' WHERE id = ?")
            ->execute([(int) $input['parent_edit_id']]);
    }
    $newEditId = (int) db()->lastInsertId();
    notify_edit_event('edit_pending', $newEditId, $clientId, $uid, [
        'author' => $user,
        'changes' => $changes,
        'recipient_user_ids' => [(int) $cli['user_id']],
    ]);
    jsonOk(['edit_id' => $newEditId]);
}

case 'list_pending': {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    $sql = "SELECT * FROM dossier_edits WHERE status = 'pending'";
    $params = [];
    if ($clientId) { $sql .= " AND client_id = ?"; $params[] = $clientId; }
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['changes'] = json_decode($r['changes'], true);
    unset($r);
    jsonOk(['edits' => $rows]);
}

case 'get_edit': {
    $id = (int) ($_GET['edit_id'] ?? 0);
    if (!$id) jsonError('edit_id requis', 400);
    $stmt = db()->prepare("SELECT * FROM dossier_edits WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) jsonError('Edit introuvable', 404);
    $r['changes'] = json_decode($r['changes'], true);
    jsonOk(['edit' => $r]);
}

case 'decide': {
    $id = (int) ($input['edit_id'] ?? 0);
    $decision = $input['decision'] ?? '';
    $comment = $input['comment'] ?? null;
    if (!$id || !in_array($decision, ['approve', 'reject'], true)) {
        jsonError('edit_id et decision (approve|reject) requis', 400);
    }
    $cur = db()->prepare("SELECT * FROM dossier_edits WHERE id = ? AND status = 'pending'");
    $cur->execute([$id]);
    $edit = $cur->fetch();
    if (!$edit) jsonError('Edit non trouvable ou déjà décidé', 404);
    if ((int) $edit['author_user_id'] === $uid) jsonError("L'auteur ne peut pas valider son propre edit", 403);
    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    db()->prepare(
        "UPDATE dossier_edits SET status = ?, decided_at = NOW(), decided_by_user_id = ?, decision_type = ?, decision_comment = ? WHERE id = ?"
    )->execute([$newStatus, $uid, $decision, $comment, $id]);
    if ($decision === 'approve') {
        // Appliquer les changements au dossier + créer un snapshot.
        $changes = json_decode($edit['changes'], true) ?: [];
        $client_id = (int) $edit['client_id'];
        $cur2 = db()->prepare("SELECT * FROM clients WHERE id = ?");
        $cur2->execute([$client_id]);
        $client = $cur2->fetch();
        $data = json_decode($client['data'] ?? '{}', true) ?: [];
        foreach ($changes as $c) {
            if (!isset($c['field'])) continue;
            $f = $c['field'];
            if (in_array($f, ['prenom','nom','societe_nom','tel','email','projet','vertical'], true)) {
                db()->prepare("UPDATE clients SET $f = ? WHERE id = ?")->execute([$c['after'], $client_id]);
            } else {
                $data[$f] = $c['after'];
            }
        }
        db()->prepare("UPDATE clients SET data = ? WHERE id = ?")->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $client_id]);
        // Snapshot version.
        $maxV = (int) db()->query("SELECT COALESCE(MAX(version_num), 0) FROM dossier_versions WHERE client_id = " . $client_id)->fetchColumn();
        $cur3 = db()->prepare("SELECT * FROM clients WHERE id = ?");
        $cur3->execute([$client_id]);
        $finalClient = $cur3->fetch();
        db()->prepare(
            "INSERT INTO dossier_versions (client_id, version_num, snapshot, approved_by_edit_id) VALUES (?, ?, ?, ?)"
        )->execute([$client_id, $maxV + 1, json_encode($finalClient, JSON_UNESCAPED_UNICODE), $id]);
    }
    $eventType = $decision === 'approve' ? 'edit_approved' : 'edit_rejected';
    notify_edit_event($eventType, $id, (int) $edit['client_id'], $uid, [
        'decider' => $user,
        'decision' => $decision,
        'comment' => $comment,
        'recipient_user_ids' => [(int) $edit['author_user_id']],
    ]);
    jsonOk(['edit_id' => $id, 'status' => $newStatus]);
}

default:
    jsonError('Action inconnue (create_edit | list_pending | get_edit | decide)', 400);
}
