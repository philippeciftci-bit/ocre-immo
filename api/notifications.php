<?php
// M/2026/04/28/51 — EditConsent UI : notifications in-app multi-canal.
// Table notifications + endpoints list / count_unread / mark_read / mark_all_read.
// Couplée à edit_consent.php (notify_edit_event) qui insère les rows.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

function ensureNotificationsSchema() {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            link_path VARCHAR(255) NULL,
            ref_type VARCHAR(64) NULL,
            ref_id BIGINT UNSIGNED NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_unread (user_id, is_read, created_at),
            INDEX idx_ref (ref_type, ref_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}

ensureNotificationsSchema();

switch ($action) {

case 'list': {
    $limit = min((int) ($_GET['limit'] ?? 50), 200);
    $st = db()->prepare(
        "SELECT id, type, title, body, link_path, ref_type, ref_id, is_read, read_at, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit"
    );
    $st->execute([$uid]);
    jsonOk(['notifications' => $st->fetchAll()]);
}

case 'count_unread': {
    $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $st->execute([$uid]);
    jsonOk(['count' => (int) $st->fetchColumn()]);
}

case 'mark_read': {
    $id = (int) ($input['notif_id'] ?? $_GET['notif_id'] ?? 0);
    if (!$id) jsonError('notif_id requis', 400);
    db()->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")
        ->execute([$id, $uid]);
    jsonOk(['ok' => true]);
}

case 'mark_all_read': {
    db()->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0")
        ->execute([$uid]);
    jsonOk(['ok' => true]);
}

default:
    jsonError('Action inconnue (list | count_unread | mark_read | mark_all_read)', 400);
}
