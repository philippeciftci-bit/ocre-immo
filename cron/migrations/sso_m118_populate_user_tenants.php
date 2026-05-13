<?php
// M/2026/05/13/25 — SSO M118 : peuplement initial user_tenants (lazy migration).
// Source 1 : users.slug -> role 'owner'
// Source 2 : workspace_members JOIN workspaces (multi-tenant)
// Idempotent : INSERT IGNORE sur PK (user_id, tenant_slug).
require_once __DIR__ . '/../../api/db.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ins = $pdo->prepare("INSERT IGNORE INTO user_tenants (user_id, tenant_slug, role) VALUES (?,?,?)");

// Source 1 : users.slug (tenant natif owner).
$users = $pdo->query("SELECT id, slug FROM users WHERE slug IS NOT NULL AND slug <> '' AND archived_at IS NULL AND anonymized_at IS NULL")->fetchAll();
$cntOwner = 0;
foreach ($users as $u) {
    $ins->execute([(int)$u['id'], (string)$u['slug'], 'owner']);
    $cntOwner += $ins->rowCount();
}

// Source 2 : workspace_members (multi-tenant cross-references).
$cntWs = 0;
try {
    $ws = $pdo->query(
        "SELECT m.user_id, w.slug, COALESCE(m.role,'agent') role
         FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.left_at IS NULL AND w.archived_at IS NULL"
    )->fetchAll();
    foreach ($ws as $w) {
        $role = in_array($w['role'], ['owner','agent','invite'], true) ? $w['role'] : 'agent';
        $ins->execute([(int)$w['user_id'], (string)$w['slug'], $role]);
        $cntWs += $ins->rowCount();
    }
} catch (Throwable $e) {
    echo "[WARN] workspace_members lookup skipped : " . $e->getMessage() . "\n";
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM user_tenants")->fetchColumn();
echo "[OK] user_tenants populated : +$cntOwner from users.slug, +$cntWs from workspace_members, total = $total rows\n";
