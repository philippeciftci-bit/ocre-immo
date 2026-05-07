<?php
// M/2026/05/07/98.1 — Recherche globale super_admin (pattern Stripe Cmd+K).
// GET /api/superadmin_search.php?q=<term> -> {results: [{category, label, sublabel, url}]}
// Auth super_admin uniquement. Recherche LIKE %term% sur 5 sources cross-tenant.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) jout(['ok' => true, 'results' => []]);

$like = '%' . $q . '%';
$meta = pdo_meta();
$results = [];

// 1. Workspaces (slug, display_name, country_code)
try {
    $st = $meta->prepare("SELECT slug, display_name, type FROM workspaces WHERE archived_at IS NULL AND (slug LIKE ? OR display_name LIKE ?) LIMIT 5");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $w) {
        $results[] = [
            'category' => 'Workspaces',
            'label' => $w['slug'] . ' — ' . $w['display_name'],
            'sublabel' => 'Type ' . $w['type'],
            'url' => '/?tab=workspaces&slug=' . urlencode($w['slug']),
        ];
    }
} catch (Throwable $e) { error_log('search workspaces: ' . $e->getMessage()); }

// 2. Users (email, prenom, nom)
try {
    $st = $meta->prepare("SELECT id, email, prenom, nom, role, slug, status FROM users WHERE archived_at IS NULL AND (email LIKE ? OR prenom LIKE ? OR nom LIKE ? OR slug LIKE ?) LIMIT 5");
    $st->execute([$like, $like, $like, $like]);
    foreach ($st->fetchAll() as $u) {
        $results[] = [
            'category' => 'Utilisateurs',
            'label' => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) ?: $u['email'],
            'sublabel' => $u['email'] . ' · ' . ($u['role'] ?? '') . ' · ' . ($u['status'] ?? '') . ($u['slug'] ? ' · @' . $u['slug'] : ''),
            'url' => '/?tab=users&id=' . (int)$u['id'],
        ];
    }
} catch (Throwable $e) { error_log('search users: ' . $e->getMessage()); }

// 3. Clients cross-tenant (boucle sur workspaces)
try {
    $wspStmt = $meta->query("SELECT slug FROM workspaces WHERE type='wsp' AND archived_at IS NULL");
    foreach ($wspStmt->fetchAll() as $w) {
        $slug = $w['slug'];
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
        try {
            $tenant = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_wsp_' . $slug . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $st = $tenant->prepare("SELECT id, prenom, nom, societe_nom, projet, tel, email FROM clients WHERE deleted_at IS NULL AND (prenom LIKE ? OR nom LIKE ? OR societe_nom LIKE ? OR email LIKE ? OR tel LIKE ?) LIMIT 3");
            $st->execute([$like, $like, $like, $like, $like]);
            foreach ($st->fetchAll() as $c) {
                $name = trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')) ?: ($c['societe_nom'] ?? '(sans nom)');
                $results[] = [
                    'category' => 'Clients',
                    'label' => $name,
                    'sublabel' => '@' . $slug . ' · ' . ($c['projet'] ?? '') . ($c['email'] ? ' · ' . $c['email'] : '') . ($c['tel'] ? ' · ' . $c['tel'] : ''),
                    'url' => '/?tab=clients&workspace=' . urlencode($slug) . '&id=' . (int)$c['id'],
                ];
                if (count($results) > 50) break 2;
            }
        } catch (Throwable $e) { /* skip tenant on error */ }
    }
} catch (Throwable $e) { error_log('search clients: ' . $e->getMessage()); }

// 4. Audit log (action, target_type)
try {
    $st = $meta->prepare("SELECT a.id, a.action, a.target_type, a.target_id, a.created_at, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE (a.action LIKE ? OR a.target_type LIKE ?) ORDER BY a.created_at DESC LIMIT 5");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $a) {
        $results[] = [
            'category' => 'Audit',
            'label' => $a['action'],
            'sublabel' => ($a['email'] ?? 'system') . ' · ' . ($a['target_type'] ?? '') . ' #' . ($a['target_id'] ?? '') . ' · ' . substr((string)$a['created_at'], 0, 16),
            'url' => '/?tab=audit&id=' . (int)$a['id'],
        ];
    }
} catch (Throwable $e) { error_log('search audit: ' . $e->getMessage()); }

// 5. super_admin_events (action recente)
try {
    $st = $meta->prepare("SELECT s.id, s.action, s.created_at, u.email FROM super_admin_events s JOIN users u ON u.id = s.super_admin_user_id WHERE s.action LIKE ? ORDER BY s.created_at DESC LIMIT 3");
    $st->execute([$like]);
    foreach ($st->fetchAll() as $a) {
        $results[] = [
            'category' => 'Activité admin',
            'label' => $a['action'],
            'sublabel' => $a['email'] . ' · ' . substr((string)$a['created_at'], 0, 16),
            'url' => '/?tab=audit',
        ];
    }
} catch (Throwable $e) { error_log('search super_admin_events: ' . $e->getMessage()); }

// Log la recherche
try {
    $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, payload_json, created_at) VALUES (?, 'global_search', ?, NOW())")
        ->execute([$user['id'], json_encode(['q' => $q, 'results_count' => count($results)])]);
} catch (Throwable $_) {}

jout([
    'ok' => true,
    'q' => $q,
    'results' => $results,
    'count' => count($results),
]);
