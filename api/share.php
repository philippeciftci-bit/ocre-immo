<?php
// V17.2 Phase 2a — partage public lecture seule d'un dossier client.
// Table share_links auto-créée. Token 32 hex, expiration 7j par défaut, révocable.
require_once __DIR__ . '/db.php';
setCorsHeaders();

try {
    db()->exec("CREATE TABLE IF NOT EXISTS share_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dossier_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        owner_user_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME,
        revoked_at DATETIME NULL,
        INDEX idx_token (token),
        INDEX idx_owner (owner_user_id)
    )");
} catch (Exception $e) { /* silent */ }

$action = $_GET['action'] ?? ($_POST['action'] ?? (isset($_GET['token']) ? 'get_shared' : ''));
$input = getInput();

switch ($action) {

    case 'get_shared': {
        // M/2026/05/01/4 — fallback V20 shared_links (multi-tenant, token 32 chars hex) si non
        // trouve dans share_links V17 (single-tenant, token 16 chars hex). Retourne flags hide_*
        // figes en DB (anti-contournement URL).
        $token = $_GET['token'] ?? ($input['token'] ?? '');
        if (!$token) jsonError('token requis');
        $hide_price = 0; $hide_address = 0; $hide_identity = 0;
        $r = null;
        // Tentative V17 (legacy, table share_links sans 's', user_id propriétaire mono-tenant).
        $stmt = db()->prepare(
            "SELECT sl.*, c.data, c.projet, c.is_investisseur, c.is_draft, c.archived, c.updated_at AS client_updated
             FROM share_links sl
             JOIN clients c ON c.id = sl.dossier_id
             WHERE sl.token = ? AND sl.revoked_at IS NULL
               AND (sl.expires_at IS NULL OR sl.expires_at > NOW())
             LIMIT 1"
        );
        try { $stmt->execute([$token]); $r = $stmt->fetch(); } catch (Throwable $e) { $r = null; }
        $dossier_id = $r ? (int)$r['dossier_id'] : 0;
        $expires_at = $r['expires_at'] ?? null;
        $created_at = $r['created_at'] ?? null;
        $r_data = $r ? ($r['data'] ?? '{}') : null;
        $r_projet = $r['projet'] ?? null;
        $r_archived = $r ? (bool)(int)$r['archived'] : false;
        $r_draft = $r ? (bool)(int)$r['is_draft'] : false;
        $r_invest = $r ? (bool)(int)$r['is_investisseur'] : false;
        // Fallback V20 : table shared_links (avec 's'), wsp_slug + flags hide_*.
        if (!$r) {
            require_once __DIR__ . '/lib/router.php';
            try {
                // M/2026/05/01/5 — accepte expires_at NULL (liens permanents).
                $st2 = pdo_meta()->prepare(
                    "SELECT * FROM shared_links WHERE token = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
                );
                $st2->execute([$token]);
                $row = $st2->fetch();
                if (!$row) jsonError('Lien invalide, expiré ou révoqué', 404);
                $hide_price    = (int)($row['hide_price'] ?? 0);
                $hide_address  = (int)($row['hide_address'] ?? 0);
                $hide_identity = (int)($row['hide_identity'] ?? 0);
                $expires_at = $row['expires_at'];
                $created_at = $row['created_at'];
                $dossier_id = (int)$row['dossier_id'];
                // Lecture du dossier dans la WSp source (mode agent puis mode test).
                $slug_clean = preg_replace('/[^a-z0-9_-]/', '', $row['wsp_slug']);
                $dossier = null;
                foreach (['', '_test'] as $suffix) {
                    try {
                        $pdo = pdo_workspace('ocre_wsp_' . $slug_clean . $suffix);
                        $d = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
                        $d->execute([$dossier_id]);
                        $dr = $d->fetch();
                        if ($dr) { $dossier = $dr; break; }
                    } catch (Throwable $e) {}
                }
                if (!$dossier) jsonError('Dossier introuvable', 404);
                $r_data = $dossier['data'] ?? '{}';
                $r_projet = $dossier['projet'] ?? '';
                $r_archived = (bool)(int)($dossier['archived'] ?? 0);
                $r_draft = (bool)(int)($dossier['is_draft'] ?? 0);
                $r_invest = (bool)(int)($dossier['is_investisseur'] ?? 0);
                // Increment viewed_count
                pdo_meta()->prepare("UPDATE shared_links SET viewed_count = viewed_count + 1, last_viewed_at = NOW() WHERE id = ?")->execute([$row['id']]);
            } catch (Throwable $e) {
                jsonError('Lien invalide, expiré ou révoqué', 404);
            }
        }
        $d = json_decode($r_data ?? '{}', true) ?: [];
        $d['id'] = $dossier_id;
        $d['archived'] = $r_archived;
        $d['is_draft'] = $r_draft;
        $d['projet'] = $r_projet;
        $d['is_investisseur'] = $r_invest;
        // Photos du dossier (lecture seule — mêmes fichiers que /uploads/<id>/)
        $photos = [];
        $dir = dirname(__DIR__) . '/uploads/' . $dossier_id;
        if (is_dir($dir)) {
            $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://app.ocre.immo';
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (!is_file($p)) continue;
                $n = basename($p);
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $n)) continue;
                $photos[] = [
                    'name' => $n,
                    'url' => $base . '/uploads/' . $dossier_id . '/' . $n,
                    'size' => filesize($p),
                    'mtime' => filemtime($p),
                ];
            }
            usort($photos, fn($a, $b) => $b['mtime'] - $a['mtime']);
        }
        jsonOk([
            'dossier' => $d,
            'photos' => $photos,
            'expires_at' => $expires_at,
            'shared_at' => $created_at,
            'hide_price' => (bool)$hide_price,
            'hide_address' => (bool)$hide_address,
            'hide_identity' => (bool)$hide_identity,
        ]);
    }

    case 'create_link': {
        $user = requireAuth();
        $dossier_id = (int)($input['dossier_id'] ?? 0);
        $expires_days = max(1, min(90, (int)($input['expires_days'] ?? 7)));
        if (!$dossier_id) jsonError('dossier_id requis');

        // Vérifier ownership
        $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ?");
        $chk->execute([$dossier_id, $user['id']]);
        if (!$chk->fetch()) jsonError('Accès refusé', 403);

        $token = bin2hex(random_bytes(16));
        $stmt = db()->prepare(
            "INSERT INTO share_links (dossier_id, token, owner_user_id, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
        );
        $stmt->execute([$dossier_id, $token, $user['id'], $expires_days]);
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://app.ocre.immo';
        jsonOk([
            'token' => $token,
            'url' => $base . '/s/' . $token,
            'expires_days' => $expires_days,
        ]);
    }

    case 'revoke': {
        $user = requireAuth();
        $token = $input['token'] ?? '';
        if (!$token) jsonError('token requis');
        $stmt = db()->prepare(
            "UPDATE share_links SET revoked_at = NOW() WHERE token = ? AND owner_user_id = ?"
        );
        $stmt->execute([$token, $user['id']]);
        jsonOk(['revoked' => (int)$stmt->rowCount()]);
    }

    case 'list_mine': {
        $user = requireAuth();
        $stmt = db()->prepare(
            "SELECT id, dossier_id, token, created_at, expires_at, revoked_at
             FROM share_links WHERE owner_user_id = ? ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute([$user['id']]);
        jsonOk(['links' => $stmt->fetchAll()]);
    }

    default:
        jsonError('Action inconnue', 404);
}
