<?php
// M/2026/04/28/15 — Backend matchs : tables + endpoints CRUD.
// Pas d'algo de matching, pas d'UI, pas de hooks (missions 16-17-18 séparées).
require_once __DIR__ . '/db.php';
setCorsHeaders();
ensureMatchesSchema();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) $user['id'];

function ensureMatchesSchema() {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS match_preferences (
            user_id INT NOT NULL PRIMARY KEY,
            seuil_min_pct TINYINT UNSIGNED NOT NULL DEFAULT 70,
            tolerance_budget_pct INT UNSIGNED NOT NULL DEFAULT 10,
            tolerance_surface_pct INT UNSIGNED NOT NULL DEFAULT 25,
            tolerance_terrain_pct INT UNSIGNED NOT NULL DEFAULT 50,
            tolerance_chambres TINYINT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dossier_a_id INT NOT NULL,
            dossier_b_id INT NOT NULL,
            score_pct TINYINT UNSIGNED NOT NULL,
            source ENUM('interne','archive','externe') NOT NULL DEFAULT 'interne',
            status ENUM('non_vu','vu','pertinent','surveiller','ecarte') NOT NULL DEFAULT 'non_vu',
            owner_user_ids TEXT NOT NULL,
            criteres_matched TEXT NULL,
            source_externe_url TEXT NULL,
            source_externe_site VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            seen_at DATETIME NULL,
            classified_at DATETIME NULL,
            classified_by_user_id INT NULL,
            UNIQUE KEY uniq_pair (dossier_a_id, dossier_b_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_owners (owner_user_ids(255))
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) { /* idempotent, silent */ }
    $done = true;
}

function ownerFilterClause(): string {
    // Filtre strict : le user_id authentifié doit figurer dans owner_user_ids (TEXT JSON array).
    return "JSON_VALID(owner_user_ids) = 1 AND JSON_CONTAINS(owner_user_ids, ?)";
}

function fetchMatch(int $id, int $uid): ?array {
    $stmt = db()->prepare("SELECT * FROM matches WHERE id = ? AND " . ownerFilterClause() . " LIMIT 1");
    $stmt->execute([$id, json_encode($uid)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function decodeMatch(array $row): array {
    $row['owner_user_ids'] = json_decode($row['owner_user_ids'] ?? '[]', true) ?: [];
    $row['criteres_matched'] = $row['criteres_matched'] ? (json_decode($row['criteres_matched'], true) ?: []) : [];
    foreach (['id','dossier_a_id','dossier_b_id','score_pct','classified_by_user_id'] as $k) {
        if (isset($row[$k]) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    return $row;
}

switch ($action) {

case 'list': {
    // M/2026/04/28/19 — alias 'a_traiter' = non_vu + vu (matchs non encore
    // classés Pertinent/À surveiller/Écarté). Onglet « À traiter » côté UI.
    $statusFilter = $_GET['status'] ?? 'all';
    $allowed = ['non_vu','vu','pertinent','surveiller','ecarte','a_traiter','all'];
    if (!in_array($statusFilter, $allowed, true)) jsonError('status invalide', 400);
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

    $sql = "SELECT * FROM matches WHERE " . ownerFilterClause();
    $params = [json_encode($uid)];
    if ($statusFilter === 'a_traiter') {
        $sql .= " AND status IN ('non_vu', 'vu')";
    } elseif ($statusFilter !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('decodeMatch', $stmt->fetchAll());

    // M/2026/04/28/22 — Joint les dossiers a/b en batch (1 SELECT IN, pas N+1)
    // pour que la liste affiche le nom de chaque dossier (régression « Dossier
    // introuvable »).
    if (count($rows) > 0) {
        $ids = [];
        foreach ($rows as $r) { $ids[] = $r['dossier_a_id']; $ids[] = $r['dossier_b_id']; }
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtCli = db()->prepare("SELECT * FROM clients WHERE id IN ($placeholders)");
        $stmtCli->execute($ids);
        $clients = [];
        foreach ($stmtCli->fetchAll() as $c) $clients[(int) $c['id']] = $c;
        foreach ($rows as &$r) {
            $r['dossier_a'] = $clients[$r['dossier_a_id']] ?? null;
            $r['dossier_b'] = $clients[$r['dossier_b_id']] ?? null;
        }
        unset($r);
    }
    jsonOk(['matches' => $rows, 'count' => count($rows)]);
}

case 'count': {
    $stmt = db()->prepare(
        "SELECT status, COUNT(*) AS n FROM matches WHERE " . ownerFilterClause() . " GROUP BY status"
    );
    $stmt->execute([json_encode($uid)]);
    $counts = ['non_vu' => 0, 'vu' => 0, 'pertinent' => 0, 'surveiller' => 0, 'ecarte' => 0];
    foreach ($stmt->fetchAll() as $r) $counts[$r['status']] = (int) $r['n'];
    jsonOk(['counts' => $counts]);
}

case 'get': {
    $id = (int) ($_GET['match_id'] ?? $input['match_id'] ?? 0);
    if ($id <= 0) jsonError('match_id requis', 400);
    $m = fetchMatch($id, $uid);
    if (!$m) jsonError('Match introuvable', 404);
    $m = decodeMatch($m);
    // Joindre les 2 dossiers complets côté client.
    $stmtCli = db()->prepare("SELECT * FROM clients WHERE id IN (?, ?)");
    $stmtCli->execute([$m['dossier_a_id'], $m['dossier_b_id']]);
    $clients = [];
    foreach ($stmtCli->fetchAll() as $c) $clients[(int) $c['id']] = $c;
    $m['dossier_a'] = $clients[$m['dossier_a_id']] ?? null;
    $m['dossier_b'] = $clients[$m['dossier_b_id']] ?? null;
    jsonOk(['match' => $m]);
}

case 'classify': {
    $id = (int) ($input['match_id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    $allowedClassify = ['pertinent','surveiller','ecarte'];
    if ($id <= 0) jsonError('match_id requis', 400);
    if (!in_array($newStatus, $allowedClassify, true)) jsonError('status invalide (pertinent|surveiller|ecarte)', 400);
    $existing = fetchMatch($id, $uid);
    if (!$existing) jsonError('Match introuvable', 404);
    $upd = db()->prepare(
        "UPDATE matches SET status = ?, classified_at = NOW(), classified_by_user_id = ? WHERE id = ?"
    );
    $upd->execute([$newStatus, $uid, $id]);
    $m = decodeMatch(fetchMatch($id, $uid));
    // M/2026/05/09/43 — M89 : push 'proposal' aux co-owners du match si statut 'pertinent'
    // (agent A pousse une proposition à ses partenaires WSc pour suite commerciale).
    if ($newStatus === 'pertinent') {
        // M116d — emit webhook events match.detected + proposal.received (graceful, ne bloque pas push notify)
        @require_once __DIR__ . '/lib/webhook_emit.php';
        if (function_exists('emit_event')) {
            $tenantSlugW = $_SERVER['HTTP_X_TENANT_SLUG'] ?? (preg_match('/^([a-z0-9-]+)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $mh) ? $mh[1] : '');
            if ($tenantSlugW) {
                $payloadW = ['match_id' => $id, 'score_pct' => (int)($m['score_pct'] ?? 0), 'tenant_user_id' => $uid];
                emit_event($tenantSlugW, 'match.detected', $payloadW);
                emit_event($tenantSlugW, 'proposal.received', $payloadW);
            }
        }
        @require_once __DIR__ . '/lib/push_notify.php';
        if (function_exists('ocre_push_notify')) {
            $owners = is_array($m['owner_user_ids'] ?? null) ? $m['owner_user_ids'] : [];
            $score = (int) ($m['score_pct'] ?? 0);
            foreach ($owners as $ouid) {
                $ouid = (int) $ouid;
                if ($ouid <= 0 || $ouid === $uid) continue; // skip soi-même
                try {
                    ocre_push_notify($ouid, 'proposal',
                        '📨 Proposition reçue ' . $score . '%',
                        'Un confrère t\'a transmis une proposition pertinente',
                        '/matches/' . $id);
                } catch (Throwable $e) { /* swallow */ }
            }
        }
    }
    jsonOk(['match' => $m]);
}

case 'mark_seen': {
    $id = (int) ($input['match_id'] ?? 0);
    if ($id <= 0) jsonError('match_id requis', 400);
    $existing = fetchMatch($id, $uid);
    if (!$existing) jsonError('Match introuvable', 404);
    if ($existing['status'] === 'non_vu') {
        $upd = db()->prepare("UPDATE matches SET status = 'vu', seen_at = NOW() WHERE id = ?");
        $upd->execute([$id]);
    }
    $m = decodeMatch(fetchMatch($id, $uid));
    jsonOk(['match' => $m]);
}

case 'get_preferences': {
    $stmt = db()->prepare("SELECT * FROM match_preferences WHERE user_id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) {
        // Pas de ligne : retourne les défauts en mémoire (sans créer la ligne).
        jsonOk(['preferences' => [
            'user_id' => $uid,
            'seuil_min_pct' => 70,
            'tolerance_budget_pct' => 10,
            'tolerance_surface_pct' => 25,
            'tolerance_terrain_pct' => 50,
            'tolerance_chambres' => 1,
            'updated_at' => null,
        ], 'is_default' => true]);
    }
    foreach (['user_id','seuil_min_pct','tolerance_budget_pct','tolerance_surface_pct','tolerance_terrain_pct','tolerance_chambres'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    jsonOk(['preferences' => $row, 'is_default' => false]);
}

case 'set_preferences': {
    $allowedSeuil = [50, 60, 70, 80, 90, 100];
    $seuil = (int) ($input['seuil_min_pct'] ?? 70);
    if (!in_array($seuil, $allowedSeuil, true)) jsonError('seuil_min_pct invalide (50/60/70/80/90/100)', 400);
    $tolBudget = max(0, min(100, (int) ($input['tolerance_budget_pct'] ?? 10)));
    $tolSurface = max(0, min(200, (int) ($input['tolerance_surface_pct'] ?? 25)));
    $tolTerrain = max(0, min(500, (int) ($input['tolerance_terrain_pct'] ?? 50)));
    $tolChambres = max(0, min(10, (int) ($input['tolerance_chambres'] ?? 1)));

    $stmt = db()->prepare(
        "INSERT INTO match_preferences (user_id, seuil_min_pct, tolerance_budget_pct, tolerance_surface_pct, tolerance_terrain_pct, tolerance_chambres)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            seuil_min_pct = VALUES(seuil_min_pct),
            tolerance_budget_pct = VALUES(tolerance_budget_pct),
            tolerance_surface_pct = VALUES(tolerance_surface_pct),
            tolerance_terrain_pct = VALUES(tolerance_terrain_pct),
            tolerance_chambres = VALUES(tolerance_chambres)"
    );
    $stmt->execute([$uid, $seuil, $tolBudget, $tolSurface, $tolTerrain, $tolChambres]);

    $get = db()->prepare("SELECT * FROM match_preferences WHERE user_id = ?");
    $get->execute([$uid]);
    $row = $get->fetch();
    foreach (['user_id','seuil_min_pct','tolerance_budget_pct','tolerance_surface_pct','tolerance_terrain_pct','tolerance_chambres'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    jsonOk(['preferences' => $row]);
}

default:
    jsonError('Action inconnue', 400);
}
