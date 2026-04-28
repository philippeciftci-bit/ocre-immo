<?php
// M/2026/04/28/51 — Endpoint d'application Telegram callback pour EditConsent.
// Appelé par /opt/atelier/app.py (webhook Telegram) sur callback_query inline button.
// Auth : token HMAC partagé /root/.secrets/ocre_dev_key + chat_id whitelist.
// Body JSON : { chat_id, edit_id, decision } where decision in {approve, reject}.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify_edit_event.php';
header('Content-Type: application/json; charset=utf-8');

$keyFile = '/root/.secrets/ocre_dev_key';
$expected = is_readable($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
$got = $_SERVER['HTTP_X_OCRE_DEV_KEY'] ?? '';
if (!$expected || !hash_equals($expected, $got)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
$chatId  = (string) ($payload['chat_id'] ?? '');
$editId  = (int) ($payload['edit_id'] ?? 0);
$decision = $payload['decision'] ?? '';
if (!$chatId || !$editId || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

// Récup l'utilisateur via telegram_chat_id.
$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$meta = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$st = $meta->prepare("SELECT id, email, display_name FROM users WHERE telegram_chat_id = ? LIMIT 1");
$st->execute([$chatId]);
$user = $st->fetch();
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'chat_id_not_mapped']);
    exit;
}
$uid = (int) $user['id'];

// Charger l'edit depuis le tenant correspondant. Le chat_id mapping doit avoir
// permis le dispatch côté app.py (slug). Ici on prend le tenant du host actuel
// (db.php → DB du tenant courant via slug HTTP_HOST).
$edit = db()->prepare("SELECT * FROM dossier_edits WHERE id = ? AND status = 'pending'");
$edit->execute([$editId]);
$row = $edit->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'edit_not_found_or_decided']);
    exit;
}
if ((int) $row['author_user_id'] === $uid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'cannot_decide_own_edit']);
    exit;
}

$newStatus = $decision === 'approve' ? 'approved' : 'rejected';
db()->prepare(
    "UPDATE dossier_edits SET status = ?, decided_at = NOW(), decided_by_user_id = ?, decision_type = ?, decision_comment = NULL WHERE id = ?"
)->execute([$newStatus, $uid, $decision, $editId]);

if ($decision === 'approve') {
    $changes = json_decode($row['changes'], true) ?: [];
    $clientId = (int) $row['client_id'];
    $cur2 = db()->prepare("SELECT * FROM clients WHERE id = ?");
    $cur2->execute([$clientId]);
    $client = $cur2->fetch();
    $data = json_decode($client['data'] ?? '{}', true) ?: [];
    foreach ($changes as $c) {
        if (!isset($c['field'])) continue;
        $f = $c['field'];
        if (in_array($f, ['prenom','nom','societe_nom','tel','email','projet','vertical'], true)) {
            db()->prepare("UPDATE clients SET $f = ? WHERE id = ?")->execute([$c['after'], $clientId]);
        } else {
            $data[$f] = $c['after'];
        }
    }
    db()->prepare("UPDATE clients SET data = ? WHERE id = ?")->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $clientId]);
    $maxV = (int) db()->query("SELECT COALESCE(MAX(version_num), 0) FROM dossier_versions WHERE client_id = " . $clientId)->fetchColumn();
    $cur3 = db()->prepare("SELECT * FROM clients WHERE id = ?");
    $cur3->execute([$clientId]);
    $finalClient = $cur3->fetch();
    db()->prepare(
        "INSERT INTO dossier_versions (client_id, version_num, snapshot, approved_by_edit_id) VALUES (?, ?, ?, ?)"
    )->execute([$clientId, $maxV + 1, json_encode($finalClient, JSON_UNESCAPED_UNICODE), $editId]);
}

$eventType = $decision === 'approve' ? 'edit_approved' : 'edit_rejected';
notify_edit_event($eventType, $editId, (int) $row['client_id'], $uid, [
    'decider' => $user,
    'decision' => $decision,
    'recipient_user_ids' => [(int) $row['author_user_id']],
]);

echo json_encode(['ok' => true, 'edit_id' => $editId, 'status' => $newStatus]);
