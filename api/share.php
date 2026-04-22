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
        $token = $_GET['token'] ?? ($input['token'] ?? '');
        if (!$token) jsonError('token requis');
        $stmt = db()->prepare(
            "SELECT sl.*, c.data, c.projet, c.is_investisseur, c.is_draft, c.archived, c.updated_at AS client_updated
             FROM share_links sl
             JOIN clients c ON c.id = sl.dossier_id
             WHERE sl.token = ? AND sl.revoked_at IS NULL
               AND (sl.expires_at IS NULL OR sl.expires_at > NOW())
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $r = $stmt->fetch();
        if (!$r) jsonError('Lien invalide, expiré ou révoqué', 404);
        $d = json_decode($r['data'] ?? '{}', true) ?: [];
        $d['id'] = (int)$r['dossier_id'];
        $d['archived'] = (bool)(int)$r['archived'];
        $d['is_draft'] = (bool)(int)$r['is_draft'];
        $d['projet'] = $r['projet'];
        $d['is_investisseur'] = (bool)(int)$r['is_investisseur'];
        // Photos du dossier (lecture seule — mêmes fichiers que /uploads/<id>/)
        $photos = [];
        $dir = dirname(__DIR__) . '/uploads/' . (int)$r['dossier_id'];
        if (is_dir($dir)) {
            $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://app.ocre.immo';
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (!is_file($p)) continue;
                $n = basename($p);
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $n)) continue;
                $photos[] = [
                    'name' => $n,
                    'url' => $base . '/uploads/' . (int)$r['dossier_id'] . '/' . $n,
                    'size' => filesize($p),
                    'mtime' => filemtime($p),
                ];
            }
            usort($photos, fn($a, $b) => $b['mtime'] - $a['mtime']);
        }
        jsonOk([
            'dossier' => $d,
            'photos' => $photos,
            'expires_at' => $r['expires_at'],
            'shared_at' => $r['created_at'],
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
