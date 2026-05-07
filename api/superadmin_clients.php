<?php
// M/2026/05/07/96 — Endpoint cross-tenant : liste TOUS les clients de TOUS les workspaces.
// Lecture seule super_admin. Filtres : workspace, profil, statut. Recherche : nom client.
// Pagination cursor-based simple : ?page=1&per_page=50.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$meta = pdo_meta();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;
    $filterWorkspace = (string)($_GET['workspace'] ?? '');
    $filterProfil = (string)($_GET['profil'] ?? '');
    $filterStatut = (string)($_GET['statut'] ?? '');
    $search = trim((string)($_GET['q'] ?? ''));

    // Lister les workspaces actifs (type=wsp)
    $wspStmt = $meta->query("SELECT slug, display_name FROM workspaces WHERE type='wsp' AND archived_at IS NULL ORDER BY slug");
    $workspaces = $wspStmt->fetchAll();

    // Log super_admin_events
    try {
        $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, created_at) VALUES (?, 'clients_list', NOW())")
            ->execute([$user['id']]);
    } catch (Throwable $_) {}

    $allClients = [];
    foreach ($workspaces as $w) {
        $slug = $w['slug'];
        if ($filterWorkspace !== '' && $filterWorkspace !== $slug) continue;
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
        $dbName = 'ocre_wsp_' . $slug;
        try {
            $tenant = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $sql = "SELECT id, prenom, nom, societe_nom, projet, is_draft, archived, created_at, updated_at FROM clients WHERE deleted_at IS NULL";
            $params = [];
            if ($filterProfil !== '') { $sql .= " AND projet = ?"; $params[] = $filterProfil; }
            if ($filterStatut === 'brouillon') { $sql .= " AND is_draft = 1 AND archived = 0"; }
            elseif ($filterStatut === 'archive') { $sql .= " AND archived = 1"; }
            elseif ($filterStatut === 'enregistre') { $sql .= " AND is_draft = 0 AND archived = 0"; }
            if ($search !== '') {
                $sql .= " AND (prenom LIKE ? OR nom LIKE ? OR societe_nom LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }
            $sql .= " ORDER BY updated_at DESC";
            $st = $tenant->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $row) {
                $statut = $row['archived'] ? 'archive' : ($row['is_draft'] ? 'brouillon' : 'enregistre');
                $allClients[] = [
                    'workspace_slug' => $slug,
                    'workspace_name' => $w['display_name'],
                    'id' => (int)$row['id'],
                    'nom_complet' => trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')),
                    'societe' => $row['societe_nom'] ?? '',
                    'profil' => $row['projet'] ?? '',
                    'statut' => $statut,
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log("superadmin_clients $slug: " . $e->getMessage());
        }
    }

    // Tri global par updated_at desc
    usort($allClients, function ($a, $b) {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });

    $total = count($allClients);
    $paged = array_slice($allClients, $offset, $perPage);

    jout([
        'ok' => true,
        'clients' => $paged,
        'workspaces' => $workspaces,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => (int)ceil($total / $perPage),
    ]);
}

jout(['ok' => false, 'error' => 'action invalide'], 400);
