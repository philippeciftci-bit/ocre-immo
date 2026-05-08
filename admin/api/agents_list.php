<?php
// A2/2026-05-04 — Liste des agents (super-admin)
// GET /admin/api/agents_list.php
// Retour : agents[] avec email, nom, slug, statut, dates, taille DB, dossiers + stats agregat

require_once __DIR__ . '/_admin_lib.php';
setCorsHeaders();

$ctx = admin_require_super();
$pdo = admin_meta_pdo();

// Recupere tous les utilisateurs actifs/suspendus + slug effectif via workspace_members.
$rows = $pdo->query(
    "SELECT u.id, u.email, u.display_name, u.role, u.status, u.is_suspended,
            u.created_at, u.last_login_at, u.last_login,
            COALESCE(w.slug, u.slug) AS slug,
            w.id AS workspace_id, w.type AS workspace_type, w.archived_at AS workspace_archived
     FROM users u
     LEFT JOIN workspace_members wm
       ON wm.user_id = u.id AND wm.left_at IS NULL
     LEFT JOIN workspaces w
       ON w.id = wm.workspace_id AND w.archived_at IS NULL
     WHERE u.archived_at IS NULL
       AND u.status != 'deleted'
     ORDER BY u.id ASC"
)->fetchAll();

// Pour chaque slug actif, calculer taille DB + count dossiers.
$agents = [];
foreach ($rows as $r) {
    $slug = $r['slug'] ?? null;
    $dbName = ($slug && $r['workspace_type'] !== 'wsc') ? ('ocre_wsp_' . $slug) : null;
    $dbSizeMb = null;
    $dossiersCount = null;

    if ($dbName) {
        try {
            $st = $pdo->prepare(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
                 FROM information_schema.tables WHERE table_schema = ?"
            );
            $st->execute([$dbName]);
            $dbSizeMb = (float) ($st->fetchColumn() ?: 0);
            if ($dbSizeMb === 0.0) $dbSizeMb = null;
        } catch (Throwable $e) { $dbSizeMb = null; }

        try {
            // table_schema must be validated since identifier injection isn't supported via ?
            // dbName est compose de prefixe fixe + slug deja valide (alphanum+-) en DB.
            if (preg_match('/^ocre_wsp_[a-z0-9_-]{1,50}$/i', $dbName)) {
                $st2 = $pdo->query("SELECT COUNT(*) FROM `$dbName`.clients");
                $dossiersCount = (int) $st2->fetchColumn();
            }
        } catch (Throwable $e) { $dossiersCount = null; }
    }

    // last_login fallback : last_login_at (V20) > last_login (legacy)
    $lastLogin = $r['last_login_at'] ?? $r['last_login'] ?? null;

    $agents[] = [
        'id'              => (int) $r['id'],
        'email'           => $r['email'],
        'display_name'    => $r['display_name'],
        'role'            => $r['role'],
        'status'          => $r['status'],
        'is_suspended'    => (int) ($r['is_suspended'] ?? 0),
        'slug'            => $slug,
        'workspace_id'    => $r['workspace_id'] ? (int) $r['workspace_id'] : null,
        'created_at'      => $r['created_at'],
        'last_login_at'   => $lastLogin,
        'db_size_mb'      => $dbSizeMb,
        'dossiers_count'  => $dossiersCount,
    ];
}

// Stats agregat.
$total = count($agents);
$active = $suspended = $thisMonth = 0;
$ymCurrent = date('Y-m');
foreach ($agents as $a) {
    if ($a['status'] === 'suspended' || (int) $a['is_suspended'] === 1) $suspended++;
    elseif ($a['status'] === 'active') $active++;
    if (substr((string) $a['created_at'], 0, 7) === $ymCurrent) $thisMonth++;
}

admin_jout([
    'ok' => true,
    'agents' => $agents,
    'total' => $total,
    'stats' => [
        'total' => $total,
        'active' => $active,
        'suspended' => $suspended,
        'inscribed_this_month' => $thisMonth,
    ],
]);
