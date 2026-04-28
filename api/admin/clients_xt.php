<?php
// M/2026/04/28/52 — Dashboard super-admin : liste dossiers transverse multi-tenant.
require_once __DIR__ . '/../db.php';
setCorsHeaders();

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);

$action = $_GET['action'] ?? 'list';
$q = trim($_GET['q'] ?? '');
$profil = trim($_GET['profil'] ?? '');
$limit = min((int) ($_GET['limit'] ?? 200), 1000);

if ($action === 'list') {
    $rows = [];
    try {
        $sysDsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
        $sys = new PDO($sysDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbs = $sys->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dbs as $dbName) {
            try {
                $td = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $sql = "SELECT id, prenom, nom, societe_nom, projet, vertical, tel, email, user_id, created_at, updated_at FROM clients WHERE deleted_at IS NULL";
                $params = [];
                if ($q) {
                    $sql .= " AND (prenom LIKE ? OR nom LIKE ? OR societe_nom LIKE ? OR email LIKE ? OR tel LIKE ?)";
                    $like = "%$q%";
                    $params = [$like, $like, $like, $like, $like];
                }
                if ($profil) {
                    $sql .= " AND projet = ?";
                    $params[] = $profil;
                }
                $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
                $st = $td->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll() as $r) {
                    $r['_tenant_db'] = $dbName;
                    $rows[] = $r;
                }
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
    usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOk(['clients' => array_slice($rows, 0, $limit), 'total' => count($rows)]);
}

jsonError('Action inconnue', 400);
