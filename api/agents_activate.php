<?php
// M/2026/05/07/7 — Endpoint activation agent post-inscription.
// GET ?token=<activation_token> -> lookup users meta + provision DB ocre_wsp_<slug> +
// flip status pending_activation -> active + clear token + redirect /login/?activated=1.
//
// Réponses :
//   302 redirect /login/?activated=1                  (succes)
//   404 page propre "Lien invalide ou deja utilise"  (token non trouve / status != pending)
//   410 page propre "Lien expire"                    (token expire)
//   500 page propre "Erreur technique"               (provisioning fail, status reste pending)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_provision.php';
setCorsHeaders();

function _activate_html_page(int $status, string $title, string $message, ?string $cta = null): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $messageEsc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $ctaHtml = '';
    if ($cta) $ctaHtml = '<p><a href="' . htmlspecialchars($cta, ENT_QUOTES, 'UTF-8') . '" style="color:#8B5E3C">Retour</a></p>';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>' . $titleEsc . ' — Oi Agent</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font-family:system-ui,-apple-system,sans-serif;background:#FCFAF6;color:#2A2018;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center}h1{font-family:Georgia,serif;color:#8B5E3C;font-size:26px;margin:0 0 12px}p{color:#5A4E3D;max-width:420px;line-height:1.5}</style></head><body><h1>' . $titleEsc . '</h1><p>' . $messageEsc . '</p>' . $ctaHtml . '</body></html>';
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
    _activate_html_page(404, 'Lien invalide', "Le lien d'activation est manquant ou mal formé. Vérifiez votre email ou demandez un nouveau lien.");
}

try {
    $pdoMeta = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    _activate_html_page(500, 'Erreur technique', 'Connexion base impossible. Réessayez dans un instant ou contactez support@ocre.immo.');
}

$st = $pdoMeta->prepare("SELECT id, email, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? AND status = 'pending_activation' AND archived_at IS NULL LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    _activate_html_page(404, 'Lien invalide', "Ce lien d'activation n'est pas valide ou a déjà été utilisé. Si tu viens de t'inscrire, vérifie ta boîte mail (et les spams).");
}

// Verif expiration : token expires_at < NOW() = 410 Gone.
$expiresAt = (string)($user['activation_token_expires_at'] ?? '');
if ($expiresAt && strtotime($expiresAt) < time()) {
    _activate_html_page(410, 'Lien expiré', "Ce lien d'activation a expiré. Demande un nouveau lien depuis la page d'inscription.");
}

$slug = (string)$user['slug'];
if ($slug === '') {
    _activate_html_page(500, 'Erreur technique', 'Slug agent manquant. Contacte support@ocre.immo en mentionnant ton email.');
}

// Provisioning DB workspace.
$prov = provision_agent_workspace($slug, $pdoMeta);

// Idempotence : si DB existe deja (cas re-activation interrompue avant flip status), on tolère.
$tolerableExisting = isset($prov['error']) && $prov['error'] === 'database_already_exists';
if (!$prov['ok'] && !$tolerableExisting) {
    @error_log('[agents_activate] provision_failed user_id=' . $user['id'] . ' slug=' . $slug . ' detail=' . ($prov['detail'] ?? ''));
    _activate_html_page(500, 'Erreur technique', "Impossible de finaliser ton activation. Notre équipe est notifiée. Réessaye dans quelques minutes ou contacte support@ocre.immo.");
}

// M/2026/05/08/28 — workspace provisionne mais status reste 'pending_activation'.
// Le flip vers 'active' est fait par /api/agents_set_password.php apres que l agent
// ait choisi (ou re-confirme) son mot de passe via la page /set-password.html.
// Token reste valide jusqu'a ce moment (TTL 48h initial).

// M/2026/05/08/52 — FIX dashboard spinner infini : INSERT meta workspaces + workspace_members
// (auth.php?action=me retournait workspaces:[] car _provision.php ne faisait que CREATE DATABASE
// sans ligne meta). Idempotent via INSERT IGNORE.
try {
    $displayName = trim((string)($user['display_name'] ?? '')) ?: ('Workspace ' . $slug);
    $pdoMeta->prepare(
        "INSERT IGNORE INTO workspaces (slug, type, display_name, country_code, created_at)
         VALUES (?, 'wsp', ?, 'FR', NOW())"
    )->execute([$slug, $displayName]);
    $wsId = (int)$pdoMeta->query("SELECT id FROM workspaces WHERE slug = " . $pdoMeta->quote($slug) . " LIMIT 1")->fetchColumn();
    if ($wsId > 0) {
        $pdoMeta->prepare(
            "INSERT IGNORE INTO workspace_members (workspace_id, user_id, role, joined_at)
             VALUES (?, ?, 'owner', NOW())"
        )->execute([$wsId, (int)$user['id']]);
    }
} catch (Throwable $e) {
    @error_log('[agents_activate] workspace_meta_insert_failed user_id=' . $user['id'] . ' slug=' . $slug . ' err=' . $e->getMessage());
}

// Log activation locale dans le workspace nouvellement provisionne.
try {
    $pdoWsp = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_wsp_' . $slug . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdoWsp->prepare("INSERT INTO logs (action, user_id, payload, ip) VALUES ('workspace_provisioned', ?, ?, ?)")
        ->execute([(int)$user['id'], json_encode(['provisioned_at' => date('c')]), $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Throwable $_) { /* log best-effort, fail silencieux */ }

// M/2026/05/08/28 — Redirect vers page set-password dediee (token preserve dans URL).
// Plus de redirect direct vers /login (cause de la confusion code/password + boucle).
header('Location: https://app.ocre.immo/set-password.html?token=' . urlencode($token));
exit;
