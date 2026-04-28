<?php
// M/2026/04/28/31 — Section IV Phase 1 : événements activité par dossier.
// Types : appel | rdv | document | note. Statuts : prevu | en_attente | reporte | fait | annule.
// Reminder offset minutes stocké pour Phase 2 (cron Telegram à venir).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) $user['id'];

function ensureEventsSchema() {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS events (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            owner_user_id INT UNSIGNED NOT NULL,
            type ENUM('appel','rdv','document','note') NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            scheduled_at DATETIME NULL,
            status ENUM('prevu','en_attente','reporte','fait','annule') NOT NULL DEFAULT 'prevu',
            reminder_offset_minutes INT NULL,
            reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
            document_recipient VARCHAR(255) NULL,
            document_attachment_path VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_client_scheduled (client_id, scheduled_at),
            INDEX idx_owner (owner_user_id),
            INDEX idx_reminder (reminder_sent, scheduled_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}

function checkClientOwnership(int $clientId, int $uid): bool {
    $stmt = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$clientId, $uid]);
    return (bool) $stmt->fetch();
}

ensureEventsSchema();

switch ($action) {

case 'list': {
    $clientId = (int) ($_GET['client_id'] ?? $input['client_id'] ?? 0);
    if (!$clientId || !checkClientOwnership($clientId, $uid)) jsonError('client_id requis ou non autorisé', 403);
    $stmt = db()->prepare("SELECT * FROM events WHERE client_id = ? AND owner_user_id = ?
                           ORDER BY scheduled_at IS NULL, scheduled_at DESC, created_at DESC");
    $stmt->execute([$clientId, $uid]);
    jsonOk(['events' => $stmt->fetchAll()]);
}

case 'create': {
    $clientId = (int) ($input['client_id'] ?? 0);
    if (!$clientId || !checkClientOwnership($clientId, $uid)) jsonError('client_id requis ou non autorisé', 403);
    $type = $input['type'] ?? '';
    if (!in_array($type, ['appel','rdv','document','note'], true)) jsonError('type invalide', 400);
    $title = substr(trim((string)($input['title'] ?? '')), 0, 255);
    if ($title === '') jsonError('title requis', 400);
    $description = (string)($input['description'] ?? '') ?: null;
    $scheduled = $input['scheduled_at'] ?? null;
    $status = $input['status'] ?? null;
    if (!in_array($status, ['prevu','en_attente','reporte','fait','annule', null], true)) jsonError('status invalide', 400);
    if (!$status) {
        $status = ($scheduled && strtotime($scheduled) < time()) ? 'fait' : 'prevu';
    }
    $reminder = isset($input['reminder_offset_minutes']) ? (int) $input['reminder_offset_minutes'] : null;
    $recipient = $input['document_recipient'] ?? null;
    $stmt = db()->prepare(
        "INSERT INTO events
            (client_id, owner_user_id, type, title, description, scheduled_at, status,
             reminder_offset_minutes, document_recipient, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$clientId, $uid, $type, $title, $description, $scheduled, $status,
                    $reminder, $recipient, $uid]);
    $id = (int) db()->lastInsertId();
    jsonOk(['id' => $id]);
}

case 'update': {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonError('id requis', 400);
    $cur = db()->prepare("SELECT * FROM events WHERE id = ? AND owner_user_id = ?");
    $cur->execute([$id, $uid]);
    $row = $cur->fetch();
    if (!$row) jsonError('Événement introuvable', 404);
    $fields = []; $params = [];
    foreach (['title','description','scheduled_at','status','reminder_offset_minutes','document_recipient'] as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f];
        }
    }
    if (!$fields) jsonError('Aucun champ à mettre à jour', 400);
    $params[] = $id; $params[] = $uid;
    $sql = "UPDATE events SET " . implode(', ', $fields) . " WHERE id = ? AND owner_user_id = ?";
    db()->prepare($sql)->execute($params);
    jsonOk(['id' => $id]);
}

case 'delete': {
    $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonError('id requis', 400);
    db()->prepare("DELETE FROM events WHERE id = ? AND owner_user_id = ?")->execute([$id, $uid]);
    jsonOk(['id' => $id]);
}

case 'mark_done': {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonError('id requis', 400);
    db()->prepare("UPDATE events SET status = 'fait' WHERE id = ? AND owner_user_id = ?")->execute([$id, $uid]);
    jsonOk(['id' => $id]);
}

default:
    jsonError('Action inconnue (list|create|update|delete|mark_done)', 400);
}
