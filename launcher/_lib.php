<?php
// M/2026/05/13/26 — Launcher app.ocre.immo : helpers communs.
// Reutilise sso_lib.php deja deploye (M/13/18 + M/13/25). Aucune modification backend.
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/sso/sso_lib.php';

function launcher_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function launcher_security_headers(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src \'self\' data:; script-src \'self\' \'unsafe-inline\'; connect-src \'self\'; form-action \'self\'; frame-ancestors \'self\'');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

function launcher_current_user(): ?array {
    $data = sso_get_cookie();
    if (!$data) return null;
    try {
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $st = $meta->prepare("SELECT id, email, prenom, nom, slug FROM users WHERE id = ? AND COALESCE(archived_at,'') = '' AND COALESCE(anonymized_at,'') = '' LIMIT 1");
        $st->execute([(int)($data['user_id'] ?? 0)]);
        $u = $st->fetch();
        if (!$u) return null;
        $tSt = $meta->prepare("SELECT tenant_slug FROM user_tenants WHERE user_id = ? ORDER BY tenant_slug");
        $tSt->execute([(int)$u['id']]);
        $u['tenants'] = array_column($tSt->fetchAll(), 'tenant_slug');
        $u['current_tenant'] = $data['current_tenant'] ?? ($u['tenants'][0] ?? $u['slug'] ?? null);
        return $u;
    } catch (Throwable $e) {
        return null;
    }
}

function launcher_render_head(string $title): string {
    $t = launcher_h($title);
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FCFAF6">
<title>{$t} — Ocre</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/launcher/assets/style.css">
</head>
<body>
HTML;
}

function launcher_render_foot(): string {
    return "</body></html>";
}
