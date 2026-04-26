<?php
// V20 phase 7 — partage dossier WSp → WSc.
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/mailer.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = resolve_workspace_context();
require_write_access($ctx);
if ($ctx['workspace']['type'] !== 'wsp') jout(['ok' => false, 'error' => 'Partage uniquement depuis WSp'], 400);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$dossier_id = (int)($input['dossier_id'] ?? 0);
$wsc_slug = (string)($input['wsc_slug'] ?? '');
if (!$dossier_id || !$wsc_slug) jout(['ok' => false, 'error' => 'dossier_id + wsc_slug requis'], 400);

$meta = pdo_meta();
$ws = $meta->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsc' AND archived_at IS NULL LIMIT 1");
$ws->execute([$wsc_slug]);
$wsc = $ws->fetch();
if (!$wsc) jout(['ok' => false, 'error' => 'WSc introuvable'], 404);

$check = $meta->prepare("SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ? AND left_at IS NULL");
$check->execute([$wsc['id'], $ctx['user']['id']]);
$mem = $check->fetch();
if (!$mem) jout(['ok' => false, 'error' => 'Pas membre du WSc cible'], 403);

$total = $meta->prepare("SELECT COUNT(*) FROM workspace_members WHERE workspace_id = ? AND left_at IS NULL");
$total->execute([$wsc['id']]);
$nb_members = (int)$total->fetchColumn();
$signed = $meta->prepare("SELECT COUNT(*) FROM pact_signatures WHERE wsc_id = ? AND signed_at IS NOT NULL");
$signed->execute([$wsc['id']]);
$nb_signed = (int)$signed->fetchColumn();
if ($nb_signed < $nb_members) jout(['ok' => false, 'error' => 'WSc inactif (pacte non signé par tous)'], 400);

// Récupérer dossier source dans WSp
$pdo_src = pdo_workspace($ctx['db_name']);
$d = $pdo_src->prepare("SELECT * FROM dossiers WHERE id = ? LIMIT 1");
$d->execute([$dossier_id]);
$dossier = $d->fetch();
if (!$dossier) jout(['ok' => false, 'error' => 'Dossier introuvable'], 404);

$pdo_src->prepare("INSERT INTO dossier_origin (dossier_id, original_workspace_slug, shared_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE shared_at=NOW()")
    ->execute([$dossier_id, $wsc_slug]);
$pdo_src->prepare("INSERT INTO audit_log_local (action, user_id, target_type, target_id, payload_json, created_at) VALUES ('dossier_shared', ?, 'dossier', ?, ?, NOW())")
    ->execute([$ctx['user']['id'], $dossier_id, json_encode(['to_wsc' => $wsc_slug])]);

// Copier dans la DB du WSc
$pdo_wsc = pdo_workspace('ocre_wsc_' . $wsc_slug);
$cols = array_keys($dossier);
$placeholders = implode(',', array_fill(0, count($cols), '?'));
$colnames = implode(',', $cols);
$sql = "INSERT INTO dossiers ({$colnames}, _origin_wsp_slug, _apporteur_user_id) VALUES ({$placeholders}, ?, ?)";
try {
    $stmt = $pdo_wsc->prepare($sql);
    $vals = array_values($dossier);
    $vals[] = $ctx['workspace']['slug'];
    $vals[] = $ctx['user']['id'];
    $stmt->execute($vals);
    $new_id = $pdo_wsc->lastInsertId();
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'Copie échouée : ' . $e->getMessage()], 500);
}

// Audit + notif aux autres membres
$meta->prepare("INSERT INTO audit_log (workspace_id, actor_user_id, action, target_type, target_id, payload_json, created_at) VALUES (?, ?, 'dossier_shared', 'dossier', ?, ?, NOW())")
    ->execute([$wsc['id'], $ctx['user']['id'], $new_id, json_encode(['from_wsp' => $ctx['workspace']['slug'], 'src_id' => $dossier_id])]);

$others = $meta->prepare("SELECT u.id, u.email, u.display_name FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.left_at IS NULL AND m.user_id != ?");
$others->execute([$wsc['id'], $ctx['user']['id']]);
$prenom_app = explode(' ', $ctx['user']['display_name'] ?? $ctx['user']['email'])[0];
foreach ($others as $other) {
    $prenom = explode(' ', $other['display_name'] ?? $other['email'])[0];
    $meta->prepare("INSERT INTO notifications (user_id, type, title, body, payload_json, created_at) VALUES (?, 'dossier_shared', ?, ?, ?, NOW())")
        ->execute([$other['id'], "{$prenom_app} t'a partagé un dossier", $dossier['client_nom'] ?? 'Nouveau dossier',
                   json_encode(['wsc_slug' => $wsc_slug, 'dossier_id' => $new_id])]);
    $url = "https://{$wsc_slug}.ocre.immo/dossier/{$new_id}";
    email_dossier_shared($other['email'], $prenom, $prenom_app, $wsc['display_name'],
                         $dossier['client_nom'] ?? '', $dossier['type_bien'] ?? '', $dossier['prix'] ?? '', $url);
}

jout(['ok' => true, 'wsc_dossier_id' => (int)$new_id]);
