<?php
// V20 Mission B — endpoint shared_links : create lien public (client) ou interne (agent WSc).
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ctx = resolve_workspace_context();
require_write_access($ctx);

switch ($action) {
case 'create_link': {
    // type=client : génère lien public lecture seule, expire 7j
    $dossier_id = (int)($input['dossier_id'] ?? 0);
    if (!$dossier_id) jout(['ok' => false, 'error' => 'dossier_id requis'], 400);
    $token = bin2hex(random_bytes(32));
    pdo_meta()->prepare(
        "INSERT INTO shared_links (dossier_id, wsp_slug, type, token, created_by_user_id, expires_at)
         VALUES (?, ?, 'client', ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
    )->execute([$dossier_id, $ctx['workspace']['slug'], $token, $ctx['user']['id']]);
    $url = 'https://' . $ctx['workspace']['slug'] . '.ocre.immo/share/' . $token;
    jout(['ok' => true, 'token' => $token, 'url' => $url, 'expires_in_days' => 7]);
}

case 'create_internal': {
    // type=agent : partage vers un autre agent membre d'un WSc commun
    $dossier_id = (int)($input['dossier_id'] ?? 0);
    $target_user_id = (int)($input['target_user_id'] ?? 0);
    if (!$dossier_id || !$target_user_id) jout(['ok' => false, 'error' => 'dossier_id + target_user_id requis'], 400);
    // Vérifier qu'ils partagent au moins un WSc actif
    $check = pdo_meta()->prepare(
        "SELECT COUNT(DISTINCT m1.workspace_id) AS shared
         FROM workspace_members m1
         JOIN workspace_members m2 ON m1.workspace_id = m2.workspace_id
         JOIN workspaces w ON w.id = m1.workspace_id
         WHERE m1.user_id = ? AND m2.user_id = ?
           AND m1.left_at IS NULL AND m2.left_at IS NULL
           AND w.type = 'wsc' AND w.archived_at IS NULL"
    );
    $check->execute([$ctx['user']['id'], $target_user_id]);
    $r = $check->fetch();
    if (!$r || (int)$r['shared'] < 1) jout(['ok' => false, 'error' => 'Pas de WSc commun avec cet agent'], 403);
    $token = bin2hex(random_bytes(32));
    pdo_meta()->prepare(
        "INSERT INTO shared_links (dossier_id, wsp_slug, type, token, created_by_user_id, target_user_id, expires_at)
         VALUES (?, ?, 'agent', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
    )->execute([$dossier_id, $ctx['workspace']['slug'], $token, $ctx['user']['id'], $target_user_id]);
    // Notif in-app au destinataire
    $sender = explode(' ', $ctx['user']['display_name'] ?? $ctx['user']['email'])[0];
    pdo_meta()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, payload_json, created_at)
         VALUES (?, 'dossier_shared_internal', ?, ?, ?, NOW())"
    )->execute([
        $target_user_id,
        $sender . " t'a partagé un dossier",
        'Lien valable 30 jours',
        json_encode(['token' => $token, 'wsp_slug' => $ctx['workspace']['slug']])
    ]);
    jout(['ok' => true, 'token' => $token]);
}

case 'list_partners': {
    // Liste des agents membres des WSc auxquels l'user appartient (hors lui-même)
    $st = pdo_meta()->prepare(
        "SELECT DISTINCT u.id, u.email, u.display_name, w.slug AS wsc_slug, w.display_name AS wsc_name
         FROM workspace_members m1
         JOIN workspaces w ON w.id = m1.workspace_id AND w.type = 'wsc' AND w.archived_at IS NULL
         JOIN workspace_members m2 ON m2.workspace_id = w.id AND m2.user_id != m1.user_id AND m2.left_at IS NULL
         JOIN users u ON u.id = m2.user_id AND u.archived_at IS NULL
         WHERE m1.user_id = ? AND m1.left_at IS NULL"
    );
    $st->execute([$ctx['user']['id']]);
    jout(['ok' => true, 'partners' => $st->fetchAll()]);
}

case 'revoke': {
    $token = (string)($input['token'] ?? '');
    if (!$token) jout(['ok' => false, 'error' => 'token requis'], 400);
    pdo_meta()->prepare("UPDATE shared_links SET revoked_at = NOW() WHERE token = ? AND created_by_user_id = ? AND revoked_at IS NULL")
        ->execute([$token, $ctx['user']['id']]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue (create_link|create_internal|list_partners|revoke)'], 400);
}
