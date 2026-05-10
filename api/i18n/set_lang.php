<?php
// M113 — POST /api/i18n/set_lang.php {lang: 'fr'|'en'|'es'|'ar'}
// Sauvegarde la langue choisie dans users.lang DB tenant + cookie 1 an.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

$d = getInput();
$lang = strtolower($d['lang'] ?? 'fr');
if (!in_array($lang, ['fr', 'en', 'es', 'ar'])) jsonError('Langue invalide', 400);

// Persiste en DB ocre_meta.users (col `lang` à créer si absente)
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    @$pdo->exec("ALTER TABLE users ADD COLUMN lang VARCHAR(2) DEFAULT 'fr'");
    $st = $pdo->prepare("UPDATE users SET lang=? WHERE id=?");
    $st->execute([$lang, (int) $user['user_id']]);
} catch (Throwable $e) { @error_log('[set_lang] ' . $e->getMessage()); }

// Cookie 1 an
setcookie('ocre_lang', $lang, [
    'expires' => time() + 365 * 86400,
    'path' => '/', 'domain' => '.ocre.immo',
    'secure' => true, 'httponly' => false, // accessible JS pour t()
    'samesite' => 'Lax',
]);

jsonResponse(['ok' => true, 'lang' => $lang]);
