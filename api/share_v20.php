<?php
// V20 phase 7 — partage client (entité métier WSp/WSc) WSp → WSc.
// Schéma WSp : table `clients` = contact + annonce (vertical, payment_plan, is_published, public_*).
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
$client_id = (int)($input['client_id'] ?? 0);
$wsc_slug = (string)($input['wsc_slug'] ?? '');
if (!$client_id || !$wsc_slug) jout(['ok' => false, 'error' => 'client_id + wsc_slug requis'], 400);

$meta = pdo_meta();
$ws = $meta->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsc' AND archived_at IS NULL LIMIT 1");
$ws->execute([$wsc_slug]);
$wsc = $ws->fetch();
if (!$wsc) jout(['ok' => false, 'error' => 'WSc introuvable'], 404);

$check = $meta->prepare("SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ? AND left_at IS NULL");
$check->execute([$wsc['id'], $ctx['user']['id']]);
if (!$check->fetch()) jout(['ok' => false, 'error' => 'Pas membre du WSc cible'], 403);

$total = $meta->prepare("SELECT COUNT(*) FROM workspace_members WHERE workspace_id = ? AND left_at IS NULL");
$total->execute([$wsc['id']]);
$nb_members = (int)$total->fetchColumn();
$signed = $meta->prepare("SELECT COUNT(*) FROM pact_signatures WHERE wsc_id = ? AND signed_at IS NOT NULL");
$signed->execute([$wsc['id']]);
if ((int)$signed->fetchColumn() < $nb_members) jout(['ok' => false, 'error' => 'WSc inactif (pacte non signé par tous)'], 400);

$pdo_src = pdo_workspace($ctx['db_name']);
$d = $pdo_src->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$d->execute([$client_id]);
$client = $d->fetch();
if (!$client) jout(['ok' => false, 'error' => 'Client introuvable'], 404);

try {
    $pdo_src->exec("CREATE TABLE IF NOT EXISTS dossier_origin (
      client_id INT UNSIGNED NOT NULL,
      original_workspace_slug VARCHAR(64) NOT NULL,
      shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      archived_at DATETIME NULL, archived_reason VARCHAR(255) NULL,
      PRIMARY KEY (client_id)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}
$pdo_src->prepare("INSERT INTO dossier_origin (client_id, original_workspace_slug, shared_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE shared_at=NOW()")
    ->execute([$client_id, $wsc_slug]);
$pdo_src->prepare("INSERT INTO audit_log_local (action, user_id, target_type, target_id, payload_json, created_at) VALUES ('client_shared', ?, 'client', ?, ?, NOW())")
    ->execute([$ctx['user']['id'], $client_id, json_encode(['to_wsc' => $wsc_slug])]);

$pdo_wsc = pdo_workspace('ocre_wsc_' . $wsc_slug);
// DDL idempotent : table clients + colonnes v20 partage.
try {
    $pdo_wsc->exec("CREATE TABLE IF NOT EXISTS clients LIKE " . $ctx['db_name'] . ".clients");
} catch (Throwable $e) {}
foreach (['_origin_wsp_slug VARCHAR(64) NULL', '_apporteur_user_id INT UNSIGNED NULL', '_frozen_readonly TINYINT NOT NULL DEFAULT 0', '_frozen_label VARCHAR(255) NULL'] as $coldef) {
    $colname = strtok($coldef, ' ');
    try { $pdo_wsc->exec("ALTER TABLE clients ADD COLUMN {$coldef}"); } catch (Throwable $e) {}
}

unset($client['id']);
$cols = array_keys($client);
$colnames = implode(',', $cols);
$placeholders = implode(',', array_fill(0, count($cols), '?'));
$sql = "INSERT INTO clients ({$colnames}, _origin_wsp_slug, _apporteur_user_id) VALUES ({$placeholders}, ?, ?)";
try {
    $stmt = $pdo_wsc->prepare($sql);
    $vals = array_values($client);
    $vals[] = $ctx['workspace']['slug'];
    $vals[] = $ctx['user']['id'];
    $stmt->execute($vals);
    $new_id = (int)$pdo_wsc->lastInsertId();
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'Copie échouée : ' . $e->getMessage()], 500);
}

$nom_client = trim(($client['prenom'] ?? '') . ' ' . ($client['nom'] ?? '')) ?: ($client['societe_nom'] ?? '—');
$type_bien = $client['vertical'] ?? '';
$prix = '';

$meta->prepare("INSERT INTO audit_log (workspace_id, actor_user_id, action, target_type, target_id, payload_json, created_at) VALUES (?, ?, 'client_shared', 'client', ?, ?, NOW())")
    ->execute([$wsc['id'], $ctx['user']['id'], $new_id, json_encode(['from_wsp' => $ctx['workspace']['slug'], 'src_id' => $client_id])]);

$others = $meta->prepare("SELECT u.id, u.email, u.display_name FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.left_at IS NULL AND m.user_id != ?");
$others->execute([$wsc['id'], $ctx['user']['id']]);
$prenom_app = explode(' ', $ctx['user']['display_name'] ?? $ctx['user']['email'])[0];
foreach ($others as $other) {
    $prenom = explode(' ', $other['display_name'] ?? $other['email'])[0];
    $meta->prepare("INSERT INTO notifications (user_id, type, title, body, payload_json, created_at) VALUES (?, 'client_shared', ?, ?, ?, NOW())")
        ->execute([$other['id'], "{$prenom_app} t'a partagé un dossier", $nom_client,
                   json_encode(['wsc_slug' => $wsc_slug, 'client_id' => $new_id])]);
    $url = "https://{$wsc_slug}.ocre.immo/client/{$new_id}";
    email_dossier_shared($other['email'], $prenom, $prenom_app, $wsc['display_name'],
                         $nom_client, $type_bien, $prix, $url);
}

jout(['ok' => true, 'wsc_client_id' => $new_id]);
