<?php
// V17.15 — script one-shot : dédup linked_dossiers + delete brouillons "Sans nom" orphelins.
// IP whitelist VPS. Self-destruct option.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['46.225.215.148'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'IP non autorisée (' . $ip . ')']);
    exit;
}

$action = $_GET['action'] ?? 'dry_run';
$pdo = db();

$stmt = $pdo->query("SELECT id, user_id, data, prenom, nom, societe_nom, tel, email FROM clients");
$rows = $stmt->fetchAll();

$dedup_count = 0;
$orphan_ids = [];
$updates = [];

foreach ($rows as $r) {
    $d = json_decode($r['data'] ?? '{}', true) ?: [];
    // Dédup linked_dossiers
    $ld = $d['linked_dossiers'] ?? null;
    if (is_array($ld)) {
        $before = count($ld);
        $after = array_values(array_unique(array_map('intval', $ld)));
        // Ne pas se self-référencer
        $after = array_values(array_filter($after, fn($x) => $x !== (int)$r['id'] && $x > 0));
        if ($before !== count($after)) {
            $d['linked_dossiers'] = $after;
            $updates[] = [(int)$r['id'], json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $dedup_count++;
        }
    }
    // Détection orphelin "Sans nom" : aucune identité ET aucun contact ET data pauvre (juste linked_dossiers + quelques flags)
    $empty_identity = empty($r['prenom']) && empty($r['nom']) && empty($r['societe_nom']);
    $empty_contact = empty($r['tel']) && empty($r['email']);
    $keys = array_keys($d);
    $significant_keys = array_filter($keys, fn($k) => !in_array($k, ['id','archived','is_draft','projet','is_investisseur','updated_at','linked_dossiers','profil_type'], true));
    if ($empty_identity && $empty_contact && count($significant_keys) === 0) {
        $orphan_ids[] = (int)$r['id'];
    }
}

if ($action === 'dry_run') {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry_run',
        'total_clients' => count($rows),
        'linked_dedup_candidates' => $dedup_count,
        'orphan_sans_nom' => $orphan_ids,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply') {
    // Appliquer les updates
    $up_stmt = $pdo->prepare("UPDATE clients SET data = ? WHERE id = ?");
    foreach ($updates as [$id, $data]) $up_stmt->execute([$data, $id]);
    // Delete orphelins
    if ($orphan_ids) {
        $placeholders = implode(',', array_fill(0, count($orphan_ids), '?'));
        $del = $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
        $del->execute($orphan_ids);
    }
    echo json_encode([
        'ok' => true,
        'mode' => 'apply',
        'dedup_applied' => count($updates),
        'orphans_deleted' => count($orphan_ids),
        'orphan_ids' => $orphan_ids,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'action ?action=dry_run|apply']);
