<?php
// V20 demo purge — one-shot IP-whitelist. Supprime tous les clients marqués
// [DEMO-2026-04-24] dans data.notes pour Philippe. Retourne la liste des client_id
// supprimés pour que le script VPS puisse rm les répertoires photos côté FTP.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$st->execute(['philippe.ciftci@gmail.com']);
$u = $st->fetch();
if (!$u) { http_response_code(500); exit('Philippe not found'); }
$PID = (int) $u['id'];

// Identifie les dossiers marqués DEMO via JSON_EXTRACT.
$q = $pdo->prepare("SELECT id, prenom, nom, projet FROM clients
    WHERE user_id = ?
    AND (JSON_UNQUOTE(JSON_EXTRACT(data, '\$.notes')) LIKE '%[DEMO-2026-04-24]%')");
$q->execute([$PID]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
$ids = array_column($rows, 'id');

$dry = isset($_GET['dry']) && $_GET['dry'] === '1';
if (!$dry && $ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM clients WHERE id IN ($in) AND user_id = ?")->execute(array_merge($ids, [$PID]));
}

// V20 complément — reset aussi le profil agent si bio contient [DEMO-2026-04-24].
$profileReset = false;
$chkBio = $pdo->prepare("SELECT id, bio FROM users WHERE id = ? AND bio LIKE '%[DEMO-2026-04-24%' LIMIT 1");
$chkBio->execute([$PID]);
$hasDemoProfile = $chkBio->fetch() ? true : false;

if (!$dry && $hasDemoProfile) {
    $pdo->prepare("UPDATE users SET
        photo_url = NULL, slug = NULL, tagline = NULL, bio = NULL,
        telephone_pro = NULL, email_pro = NULL, whatsapp_pro = NULL,
        zones_intervention = NULL, specialites = NULL,
        carte_pro_numero = NULL, carte_pro_prefecture = NULL, carte_pro_date_fin = NULL,
        rcp_assureur = NULL, rcp_numero_police = NULL, rcp_montant_garantie = NULL,
        statut_public = 'brouillon'
        WHERE id = ?")->execute([$PID]);
    $profileReset = true;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'dry' => $dry,
    'deleted_count' => $dry ? 0 : count($ids),
    'matched_ids' => $ids,
    'matched_rows' => $rows,
    'philippe_user_id' => $PID,
    'agent_profile_is_demo' => $hasDemoProfile,
    'agent_profile_reset' => $profileReset,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
