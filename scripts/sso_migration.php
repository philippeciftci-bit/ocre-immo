<?php
// M99 — Script migration SSO idempotent.
// Pour chaque user tenant existant (ocre_meta.users), cree ou synchronise l'entree
// auth_users correspondante (matche par email LOWER) + propage prenom/nom.
//
// Usage CLI :
//   php sso_migration.php --dry-run    # log sans modifier
//   php sso_migration.php --apply      # ecrit en DB
//   php sso_migration.php --status     # imprime stats coverage SSO
//
// Idempotent : INSERT IGNORE sur email + UPDATE first_name/last_name si NULL ou changes.
// Log dans /var/log/ocre-sso-migration.log

if (php_sapi_name() !== 'cli') {
    http_response_code(403); echo 'CLI only'; exit;
}

require_once __DIR__ . '/../api/db.php';

$args = array_slice($argv, 1);
$mode = 'status';
foreach ($args as $a) {
    if ($a === '--dry-run') $mode = 'dry-run';
    elseif ($a === '--apply') $mode = 'apply';
    elseif ($a === '--status') $mode = 'status';
}

function logLine(string $level, string $msg): void {
    $line = '[' . date('c') . '] [sso_migration] [' . $level . '] ' . $msg;
    fwrite(STDOUT, $line . "\n");
    @file_put_contents('/var/log/ocre-sso-migration.log', $line . "\n", FILE_APPEND);
}

logLine('INFO', "mode=$mode start");

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Ensure auth_users table existe (peut tourner sans avoir charge auth/lib/auth_db.php).
$pdo->exec("CREATE TABLE IF NOT EXISTS auth_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    INDEX idx_email (email)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach ([
    "ALTER TABLE auth_users ADD COLUMN first_name VARCHAR(64) NULL",
    "ALTER TABLE auth_users ADD COLUMN last_name VARCHAR(64) NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* deja present */ }
}

if ($mode === 'status') {
    $tenants = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE archived_at IS NULL")->fetchColumn();
    $auths   = (int) $pdo->query("SELECT COUNT(*) FROM auth_users")->fetchColumn();
    $matched = (int) $pdo->query("
        SELECT COUNT(*) FROM users u
        JOIN auth_users a ON LOWER(a.email) = LOWER(u.email)
        WHERE u.archived_at IS NULL
    ")->fetchColumn();
    $unmatched = $tenants - $matched;
    logLine('INFO', "tenants_active=$tenants auth_users=$auths matched=$matched unmatched=$unmatched");
    echo json_encode([
        'tenant_users_active' => $tenants,
        'auth_users_total' => $auths,
        'matched_via_email' => $matched,
        'unmatched_tenant_users' => $unmatched,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$users = $pdo->query("
    SELECT id, email, prenom, nom
    FROM users
    WHERE archived_at IS NULL AND email IS NOT NULL AND email != ''
")->fetchAll();

$created = 0; $updated = 0; $skipped = 0;

foreach ($users as $u) {
    $email = strtolower(trim($u['email']));
    $first = $u['prenom'] !== null ? trim($u['prenom']) : null;
    $last  = $u['nom'] !== null ? trim($u['nom']) : null;

    $st = $pdo->prepare("SELECT id, first_name, last_name FROM auth_users WHERE LOWER(email) = ? LIMIT 1");
    $st->execute([$email]);
    $existing = $st->fetch();

    if (!$existing) {
        if ($mode === 'apply') {
            $ins = $pdo->prepare("INSERT INTO auth_users (email, first_name, last_name) VALUES (?, ?, ?)");
            $ins->execute([$email, $first, $last]);
        }
        logLine('INFO', "CREATE auth_user email=$email first=$first last=$last");
        $created++;
    } else {
        $needUpdate = false;
        $newFirst = $existing['first_name'];
        $newLast  = $existing['last_name'];
        // Backfill seulement si auth_users a NULL (pas ecraser ce que l'user a saisi cote SSO)
        if (($newFirst === null || $newFirst === '') && $first) { $newFirst = $first; $needUpdate = true; }
        if (($newLast  === null || $newLast === '')  && $last)  { $newLast  = $last;  $needUpdate = true; }
        if ($needUpdate) {
            if ($mode === 'apply') {
                $up = $pdo->prepare("UPDATE auth_users SET first_name = ?, last_name = ? WHERE id = ?");
                $up->execute([$newFirst, $newLast, $existing['id']]);
            }
            logLine('INFO', "UPDATE auth_user email=$email first=$newFirst last=$newLast");
            $updated++;
        } else {
            $skipped++;
        }
    }
}

logLine('INFO', "DONE mode=$mode created=$created updated=$updated skipped=$skipped");
echo json_encode([
    'mode' => $mode,
    'created' => $created,
    'updated' => $updated,
    'skipped' => $skipped,
    'total_processed' => count($users),
], JSON_PRETTY_PRINT) . "\n";
