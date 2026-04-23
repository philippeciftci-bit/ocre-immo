<?php
// V17.5 Phase 2c — bridge VPS ↔ DB OVH pour la sync Google Sheet.
// IP whitelist VPS 46.225.215.148. Auto-migrations (ALTER users + CREATE queue).
require_once __DIR__ . '/db.php';

// CORS minimal (VPS only, pas d'origin web browser)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

function requireVpsIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowed = ['46.225.215.148'];
    if (!in_array($ip, $allowed, true)) jsonError('Accès refusé (IP=' . $ip . ')', 403);
}

function ensureSchema() {
    $pdo = db();
    // Colonnes users (ADD COLUMN IF NOT EXISTS est dispo depuis MySQL 8.0 — fallback sur try/catch)
    foreach ([
        "ALTER TABLE users ADD COLUMN sync_enabled TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN sync_email VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN sheet_id VARCHAR(100) NULL",
        "ALTER TABLE users ADD COLUMN sheet_created_at DATETIME NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* colonne déjà présente */ }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ocre_sync_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        dossier_id INT NULL,
        action VARCHAR(20) NOT NULL DEFAULT 'upsert',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        error TEXT NULL,
        INDEX idx_user (user_id),
        INDEX idx_pending (processed_at, created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // V18.13 — audit log sync bidirectionnelle Sheet→App
    $pdo->exec("CREATE TABLE IF NOT EXISTS sheet_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        client_id INT NULL,
        field_changed VARCHAR(60) NOT NULL,
        old_value VARCHAR(255) NULL,
        new_value VARCHAR(255) NULL,
        source ENUM('sheet_to_app','app_to_sheet') NOT NULL DEFAULT 'sheet_to_app',
        ts DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_ts (user_id, ts),
        INDEX idx_client (client_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

requireVpsIp();
ensureSchema();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

switch ($action) {

    case 'ping':
        jsonOk(['now' => date('c')]);

    case 'pending_count': {
        $r = db()->query("SELECT COUNT(DISTINCT user_id) AS n FROM ocre_sync_queue WHERE processed_at IS NULL")->fetch();
        jsonOk(['n' => (int)($r['n'] ?? 0)]);
    }

    case 'pending_users': {
        $stmt = db()->query(
            "SELECT DISTINCT q.user_id
             FROM ocre_sync_queue q
             JOIN users u ON u.id = q.user_id
             WHERE q.processed_at IS NULL AND u.sync_enabled = 1 AND u.sync_email IS NOT NULL
             ORDER BY q.user_id"
        );
        jsonOk(['user_ids' => array_map('intval', array_column($stmt->fetchAll(), 'user_id'))]);
    }

    case 'user_snapshot': {
        $user_id = (int)($_GET['user_id'] ?? 0);
        if (!$user_id) jsonError('user_id requis');
        $stmt = db()->prepare(
            "SELECT id, email, prenom, nom, sync_enabled, sync_email, sheet_id
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) jsonError('user introuvable', 404);
        // V18.17 — exclure staged (téléchargements non validés). NULL-safe si colonne absente.
        $stmt = db()->prepare(
            "SELECT id, data, projet, is_investisseur, is_draft, archived,
                    prenom, nom, tel, email, societe_nom, updated_at
             FROM clients
             WHERE user_id = ? AND (is_staged IS NULL OR is_staged = 0)
             ORDER BY updated_at DESC"
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        $dossiers = array_map(function($r) {
            $d = json_decode($r['data'] ?? '{}', true) ?: [];
            return [
                'id' => (int)$r['id'],
                'projet' => $r['projet'] ?? 'Acheteur',
                'is_investisseur' => (bool)(int)$r['is_investisseur'],
                'is_draft' => (bool)(int)$r['is_draft'],
                'archived' => (bool)(int)$r['archived'],
                'prenom' => $r['prenom'],
                'nom' => $r['nom'],
                'tel' => $r['tel'],
                'email' => $r['email'],
                'societe_nom' => $r['societe_nom'],
                'ville' => $d['ville'] ?? '',
                'pays_residence' => $d['pays_residence'] ?? '',
                'profil_type' => $d['profil_type'] ?? 'Particulier',
                'bien' => $d['bien'] ?? [],
                'budget_min' => $d['budget_min'] ?? '',
                'budget_max' => $d['budget_max'] ?? '',
                'prix_affiche' => $d['prix_affiche'] ?? '',
                'loyer_max' => $d['loyer_max'] ?? '',
                'loyer_demande' => $d['loyer_demande'] ?? '',
                'updated_at' => $r['updated_at'],
            ];
        }, $rows);
        jsonOk(['user' => $user, 'dossiers' => $dossiers]);
    }

    case 'mark_synced': {
        $user_id = (int)($input['user_id'] ?? 0);
        if (!$user_id) jsonError('user_id requis');
        $stmt = db()->prepare("UPDATE ocre_sync_queue SET processed_at = NOW() WHERE user_id = ? AND processed_at IS NULL");
        $stmt->execute([$user_id]);
        jsonOk(['marked' => (int)$stmt->rowCount()]);
    }

    case 'set_sheet_id': {
        $user_id = (int)($input['user_id'] ?? 0);
        $sheet_id = (string)($input['sheet_id'] ?? '');
        if (!$user_id || !$sheet_id) jsonError('user_id et sheet_id requis');
        $stmt = db()->prepare("UPDATE users SET sheet_id = ?, sheet_created_at = NOW() WHERE id = ?");
        $stmt->execute([$sheet_id, $user_id]);
        jsonOk(['updated' => (int)$stmt->rowCount()]);
    }

    case 'admin_set_prefs': {
        // Set sync_enabled + sync_email pour un user par email (IP-whitelist VPS).
        $email = (string)($input['email'] ?? '');
        $sync_email = (string)($input['sync_email'] ?? '');
        $enabled = !empty($input['sync_enabled']) ? 1 : 0;
        if (!$email) jsonError('email requis');
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) jsonError('user introuvable', 404);
        $stmt = db()->prepare("UPDATE users SET sync_enabled = ?, sync_email = ? WHERE id = ?");
        $stmt->execute([$enabled, $sync_email ?: null, $u['id']]);
        if ($enabled) {
            db()->prepare("INSERT INTO ocre_sync_queue (user_id, action) VALUES (?, 'full_sync')")->execute([$u['id']]);
        }
        jsonOk(['user_id' => (int)$u['id'], 'sync_enabled' => (bool)$enabled, 'sync_email' => $sync_email]);
    }

    // V18.13 — liste users avec sheet_id (worker read-back).
    case 'sync_users_with_sheet': {
        $stmt = db()->query(
            "SELECT id, sheet_id, sync_email FROM users
             WHERE sync_enabled = 1 AND sheet_id IS NOT NULL AND sheet_id != ''"
        );
        jsonOk(['users' => $stmt->fetchAll()]);
    }

    // V18.13 — applique un changement profil détecté Sheet → DB.
    case 'apply_profil_change': {
        $user_id = (int)($input['user_id'] ?? 0);
        $client_id = (int)($input['client_id'] ?? 0);
        $new_profil = (string)($input['new_profil'] ?? '');
        if (!$user_id || !$client_id || !$new_profil) jsonError('user_id+client_id+new_profil requis');
        $valid = ['Acheteur','Vendeur','Investisseur','Bailleur','Locataire','Curieux'];
        if (!in_array($new_profil, $valid, true)) jsonError('profil invalide : ' . $new_profil);
        $st = db()->prepare("SELECT projet, data FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$client_id, $user_id]);
        $r = $st->fetch();
        if (!$r) jsonError('client introuvable ou non-owné', 404);
        $old = $r['projet'] ?? '';
        if ($old === $new_profil) jsonOk(['changed' => false, 'reason' => 'same']);
        db()->beginTransaction();
        try {
            db()->prepare("UPDATE clients SET projet = ?, is_investisseur = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                ->execute([$new_profil, $new_profil === 'Investisseur' ? 1 : 0, $client_id, $user_id]);
            $cur = json_decode($r['data'] ?: '{}', true) ?: [];
            $cur['projet'] = $new_profil;
            $cur['profil_sheet_overridden'] = true;
            $cur['profil_sheet_overridden_at'] = date('c');
            db()->prepare("UPDATE clients SET data = ? WHERE id = ?")
                ->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $client_id]);
            db()->prepare(
                "INSERT INTO sheet_sync_log (user_id, client_id, field_changed, old_value, new_value, source) VALUES (?, ?, 'projet', ?, ?, 'sheet_to_app')"
            )->execute([$user_id, $client_id, $old, $new_profil]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            jsonError('UPDATE échoué: ' . $e->getMessage(), 500);
        }
        jsonOk(['changed' => true, 'old' => $old, 'new' => $new_profil]);
    }

    case 'log_error': {
        $user_id = (int)($input['user_id'] ?? 0);
        $error = (string)($input['error'] ?? '');
        if (!$user_id) jsonError('user_id requis');
        $stmt = db()->prepare("UPDATE ocre_sync_queue SET error = ? WHERE user_id = ? AND processed_at IS NULL");
        $stmt->execute([$error, $user_id]);
        jsonOk(['logged' => (int)$stmt->rowCount()]);
    }

    default:
        jsonError('Action inconnue', 404);
}
