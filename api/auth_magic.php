<?php
// V48 — Magic link auth pour Ophélie. Deux actions :
// - generate (IP-whitelist VPS atelier ou admin) : crée/réutilise un token, retourne URL
// - consume (public, ?action=consume&mt=<token>) : valide, crée session, redirige avec
//   les credentials inline pour que le SPA React les enregistre dans localStorage.
require_once __DIR__ . '/db.php';

const MAGIC_TTL_DAYS = 7;
const MAGIC_MULTI_TTL_YEARS = 10;
const MAGIC_SESSION_DAYS = 90;

function magicEnsureSchema() {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS magic_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip_consume VARCHAR(45) NULL,
            ua_consume TEXT NULL,
            INDEX idx_token (token_hash),
            INDEX idx_user (user_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) {}
    foreach ([
        "ALTER TABLE users ADD COLUMN scope_owner_id INT NULL",
        "ALTER TABLE clients ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0",
        // V51 — multi-use + revocation
        "ALTER TABLE magic_tokens ADD COLUMN multi_use TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE magic_tokens ADD COLUMN revoked_at DATETIME NULL",
        "ALTER TABLE magic_tokens ADD COLUMN consume_count INT NOT NULL DEFAULT 0",
        "ALTER TABLE magic_tokens ADD COLUMN last_consumed_at DATETIME NULL",
    ] as $sql) {
        try { db()->exec($sql); } catch (Exception $e) {}
    }
}

function isAtelierIp(): bool {
    $allowed = ['46.225.215.148', '127.0.0.1', '::1'];
    $remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $remote)[0]);
    return in_array($ip, $allowed, true);
}

magicEnsureSchema();
$action = $_GET['action'] ?? '';

if ($action === 'generate') {
    if (!isAtelierIp()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    header('Content-Type: application/json; charset=utf-8');
    $input = getInput();
    $email = strtolower(trim($input['email'] ?? ''));
    $user_id = (int)($input['user_id'] ?? 0);
    if ($email && !$user_id) {
        $stmt = db()->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        $r = $stmt->fetch();
        if ($r) $user_id = (int)$r['id'];
    }
    if (!$user_id) { echo json_encode(['ok'=>false,'error'=>'user introuvable']); exit; }
    $stmt = db()->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) { echo json_encode(['ok'=>false,'error'=>'user invalide']); exit; }
    // V51 — multi_use=1 par défaut, TTL 10 ans (révocation manuelle = coupe-circuit).
    $multi = array_key_exists('multi_use', $input) ? (int)!!$input['multi_use'] : 1;
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    if ($multi) {
        $stmt = db()->prepare("INSERT INTO magic_tokens (user_id, token_hash, expires_at, multi_use) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? YEAR), 1)");
        $stmt->execute([$user_id, $hash, MAGIC_MULTI_TTL_YEARS]);
        $ttl = MAGIC_MULTI_TTL_YEARS . 'y';
    } else {
        $stmt = db()->prepare("INSERT INTO magic_tokens (user_id, token_hash, expires_at, multi_use) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), 0)");
        $stmt->execute([$user_id, $hash, MAGIC_TTL_DAYS]);
        $ttl = MAGIC_TTL_DAYS . 'd';
    }
    $url = 'https://ocre.immo/app/api/auth_magic.php?action=consume&mt=' . urlencode($token);
    echo json_encode(['ok'=>true, 'url'=>$url, 'user_email'=>$u['email'], 'user_id'=>$user_id, 'multi_use'=>(bool)$multi, 'expires_in'=>$ttl]);
    exit;
}

if ($action === 'revoke') {
    if (!isAtelierIp()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    header('Content-Type: application/json; charset=utf-8');
    $input = getInput();
    $token_id = (int)($input['token_id'] ?? 0);
    $user_id = (int)($input['user_id'] ?? 0);
    if ($token_id) {
        $stmt = db()->prepare("UPDATE magic_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
        $stmt->execute([$token_id]);
        echo json_encode(['ok'=>true, 'revoked'=>$stmt->rowCount()]); exit;
    }
    if ($user_id) {
        $stmt = db()->prepare("UPDATE magic_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmt->execute([$user_id]);
        // Optionnel : invalider toutes les sessions actives de cet user.
        try { db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$user_id]); } catch (Exception $e) {}
        echo json_encode(['ok'=>true, 'revoked'=>$stmt->rowCount()]); exit;
    }
    echo json_encode(['ok'=>false, 'error'=>'token_id ou user_id requis']); exit;
}

if ($action === 'list') {
    if (!isAtelierIp()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    header('Content-Type: application/json; charset=utf-8');
    $rows = db()->query("SELECT mt.id, mt.user_id, u.email, mt.created_at, mt.expires_at, mt.consumed_at, mt.revoked_at, mt.multi_use, mt.consume_count, mt.last_consumed_at FROM magic_tokens mt LEFT JOIN users u ON u.id = mt.user_id ORDER BY mt.created_at DESC LIMIT 100")->fetchAll();
    echo json_encode(['ok'=>true, 'tokens'=>$rows], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'consume') {
    // V51.2 — affichage erreurs OFF en prod (le wrap Throwable se charge du fail-safe HTML).
    ini_set('display_errors', '0');
    $token = $_GET['mt'] ?? '';
    function magicErrorPage(string $msg): void {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Lien expiré · OCRE</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>body{margin:0;background:#FBF7EF;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;color:#2A2018}'
            . '.box{max-width:420px;background:#fff;border:1.5px solid #C9B79A;border-radius:14px;padding:24px 22px;text-align:center;box-shadow:0 4px 12px rgba(139,94,60,.1)}'
            . 'h1{font-family:"Cormorant Garamond",serif;color:#8B5E3C;font-size:24px;margin:0 0 12px}p{font-size:14px;line-height:1.5;color:#5C3B1E;margin:8px 0}</style>'
            . '</head><body><div class="box"><h1>Lien expiré</h1><p>' . htmlspecialchars($msg) . '</p>'
            . '<p style="font-size:12px;color:#8B7F6E">Demande à Philippe un nouveau lien.</p></div></body></html>';
    }
    if (!$token || strlen($token) < 30) { http_response_code(400); magicErrorPage('Token manquant ou invalide.'); exit; }
    try { // V51.2 DEBUG WRAP
    $hash = hash('sha256', $token);
    $stmt = db()->prepare("SELECT id, user_id, expires_at, consumed_at, multi_use, revoked_at, consume_count FROM magic_tokens WHERE token_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); magicErrorPage('Lien introuvable.'); exit; }
    if (!empty($row['revoked_at'])) { http_response_code(410); magicErrorPage('Lien révoqué. Demande un nouveau lien à Philippe.'); exit; }
    $is_multi = (int)($row['multi_use'] ?? 0) === 1;
    if (!$is_multi && $row['consumed_at']) { http_response_code(410); magicErrorPage('Lien déjà utilisé. Demande un nouveau lien.'); exit; }
    if (strtotime($row['expires_at']) < time()) { http_response_code(410); magicErrorPage('Lien expiré. Demande un nouveau lien.'); exit; }

    // Récupère utilisateur
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$row['user_id']]);
    $u = $stmt->fetch();
    if (!$u) { http_response_code(404); magicErrorPage('Compte associé introuvable.'); exit; }

    // V51 — multi_use : ne pas setter consumed_at, incrémenter compteur + last_consumed_at.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
    try {
        if ($is_multi) {
            db()->prepare("UPDATE magic_tokens SET consume_count = consume_count + 1, last_consumed_at = NOW(), ip_consume = ?, ua_consume = ? WHERE id = ?")
                ->execute([$ip, $ua, (int)$row['id']]);
        } else {
            db()->prepare("UPDATE magic_tokens SET consumed_at = NOW(), ip_consume = ?, ua_consume = ? WHERE id = ?")
                ->execute([$ip, $ua, (int)$row['id']]);
        }
    } catch (Throwable $e) { error_log('[magic-consume-update] ' . $e->getMessage()); }

    // Crée session (réutilise sessions table existante).
    // V51.1 — wrap dans try/catch pour ne JAMAIS rester bloqué : si l'INSERT sessions
    // échoue (contrainte / colonne manquante), on continue le flow et on rapporte l'erreur.
    $sessionToken = bin2hex(random_bytes(32));
    $session_ok = false;
    $session_err = '';
    try {
        $expires_seconds = MAGIC_SESSION_DAYS * 86400;
        db()->prepare("INSERT INTO sessions (token, user_id, expires_at, ip, user_agent) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)")
            ->execute([$sessionToken, (int)$u['id'], $expires_seconds, $ip, $ua]);
        $session_ok = true;
    } catch (Throwable $e) {
        $session_err = $e->getMessage();
        error_log('[magic-consume-session] ' . $e->getMessage());
    }
    try { db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([(int)$u['id']]); } catch (Throwable $e) {}
    if (function_exists('logAccess')) { try { logAccess((int)$u['id'], 'magic_login_ok', ['token_id'=>(int)$row['id'], 'session_ok'=>$session_ok]); } catch (Throwable $e) {} }

    if (!$session_ok) {
        // Fail-safe : page erreur explicite plutôt que page blanche.
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Erreur connexion OCRE</title></head><body style="font-family:system-ui;padding:24px;background:#FBF7EF;color:#2A2018">'
            . '<h1 style="color:#C04B20">Erreur de connexion</h1>'
            . '<p>La session n\'a pas pu être créée côté serveur. Détail technique :</p>'
            . '<pre style="background:#fff;padding:10px;border-radius:8px;font-size:11px;overflow-x:auto">' . htmlspecialchars($session_err) . '</pre>'
            . '<p style="font-size:13px">Demande à Philippe de regénérer un nouveau lien.</p>'
            . '</body></html>';
        exit;
    }

    // V51.1 — REDIRECT SERVEUR SOLIDE : si possible header Location vers /app/?t=<token>,
    // ce qui rend le flow robuste même si JS désactivé / Safari iOS bloque setTimeout.
    // Le SPA peut lire ?t= et faire le bootstrap. En complément, on garde la page bridge
    // HTML qui set localStorage pour les utilisateurs déjà connectés.
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    // Refresh meta + JS double-failsafe + lien manuel cliquable si tout échoue.
    $tokenJs = json_encode($sessionToken);
    $redirect = '/app/?mt_token=' . urlencode($sessionToken);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Connexion OCRE…</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta http-equiv="refresh" content="1;url=' . htmlspecialchars($redirect) . '">'
        . '<style>'
        . 'body{margin:0;background:#FBF7EF;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#8B5E3C}'
        . '.b{text-align:center;padding:24px}.s{width:36px;height:36px;border:3px solid #E8DDD0;border-top-color:#8B5E3C;border-radius:50%;animation:r .7s linear infinite;margin:0 auto 12px}'
        . '@keyframes r{to{transform:rotate(360deg)}}h1{font-family:"Cormorant Garamond",serif;font-size:22px;font-weight:700;margin:0 0 8px}'
        . 'a.fb{display:inline-block;margin-top:12px;padding:8px 16px;background:#8B5E3C;color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:700}'
        . '</style></head><body><div class="b"><div class="s"></div><h1>Bienvenue sur OCRE Immo…</h1>'
        . '<p style="font-size:12px;color:#8B7F6E">Redirection en cours…</p>'
        . '<a class="fb" href="' . htmlspecialchars($redirect) . '">Continuer manuellement</a>'
        . '</div>'
        . '<script>'
        . 'try{localStorage.setItem("ocre_token",' . $tokenJs . ');'
        . 'localStorage.setItem("ocre_first_login","1");}catch(e){}'
        . 'window.location.replace(' . json_encode($redirect) . ');'
        . '</script></body></html>';
    exit;
    } catch (Throwable $t) { // V51.2 DEBUG WRAP
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:monospace;padding:20px;background:#FFF7F0">';
        echo '<h2 style="color:#C04B20;font-family:system-ui">[magic-consume DEBUG] ' . htmlspecialchars(get_class($t)) . '</h2>';
        echo '<p><b>Message:</b> ' . htmlspecialchars($t->getMessage()) . '</p>';
        echo '<p><b>File:</b> ' . htmlspecialchars($t->getFile()) . ':' . (int)$t->getLine() . '</p>';
        echo '<pre style="background:#fff;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto">' . htmlspecialchars($t->getTraceAsString()) . '</pre>';
        echo '</body></html>';
        error_log('[magic-consume-throwable] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
        exit;
    }
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'action inconnue']);
