<?php
// M/2026/05/14/10 — Lib partagee confirm_user_by_token utilisee par 2 endpoints :
//   - /api/auth/confirm_signup.php (JSON API, compat backward)
//   - https://auth.ocre.immo/confirm  (PHP redirect HTTP 302 direct, no HTML)

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

/**
 * Valide un activation_token et active le user. Idempotent + race-safe.
 *
 * @return array Forme :
 *   ['ok' => true, 'uid' => int, 'slug' => string]
 *   ['ok' => false, 'error' => string, 'message' => string, 'http_code' => int]
 */
function confirm_user_by_token(string $token): array {
    if ($token === '') {
        return ['ok' => false, 'error' => 'missing_token', 'message' => 'Token manquant', 'http_code' => 400];
    }

    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'db_unavailable', 'message' => 'Service indisponible', 'http_code' => 503];
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare(
        "SELECT id, email, slug, status, activation_token_version
         FROM users
         WHERE activation_token = ? AND archived_at IS NULL AND activation_token_expires_at > NOW()
         LIMIT 1 FOR UPDATE"
    );
    $st->execute([$token]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'token_invalid', 'message' => 'Lien invalide ou expire', 'http_code' => 400];
    }

    $uid = (int)$user['id'];
    $slug = (string)($user['slug'] ?? '');

    // Garde-fou anti-orphelin : verifie DB tenant + table clients.
    if (!_confirm_tenant_db_ready($slug)) {
        @file_put_contents('/var/log/ocre-signup.log', '[' . date('c') . "] confirm-lib GUARD: DB missing for slug=$slug uid=$uid, retry provision\n", FILE_APPEND);
        $cmd = sprintf('sudo /opt/ocre-app/scripts/provision-tenant.sh %s %d 2>&1', escapeshellarg($slug), $uid);
        $out = []; $rc = 0;
        @exec($cmd, $out, $rc);
        @file_put_contents('/var/log/ocre-signup.log', '[' . date('c') . "] confirm-lib PROVISION-RETRY rc=$rc slug=$slug\n" . implode("\n", $out) . "\n\n", FILE_APPEND);
        if ($rc !== 0 || !_confirm_tenant_db_ready($slug)) {
            $pdo->rollBack();
            @exec(sprintf('/root/bin/notify --project ocre --priority high --title %s --body %s 2>/dev/null',
                escapeshellarg('WORKSPACE_NOT_READY au confirm'),
                escapeshellarg("slug=$slug uid=$uid rc=$rc")
            ));
            return ['ok' => false, 'error' => 'workspace_not_ready', 'message' => 'Workspace en preparation, reessaie dans 1 minute.', 'http_code' => 503];
        }
    }

    // Activate + consomme token.
    $pdo->prepare(
        "UPDATE users SET status='active', activation_token=NULL, activation_token_expires_at=NULL,
            last_login=NOW(), failed_login_count=0, locked_until=NULL
         WHERE id=?"
    )->execute([$uid]);
    $pdo->commit();

    return ['ok' => true, 'uid' => $uid, 'slug' => $slug];
}

function _confirm_tenant_db_ready(string $slug): bool {
    if ($slug === '') return false;
    try {
        $pdo2 = new PDO('mysql:host=' . DB_HOST . ';dbname=information_schema;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbName = 'ocre_wsp_' . $slug;
        $q = $pdo2->prepare("SELECT COUNT(*) FROM tables WHERE table_schema = ? AND table_name = 'clients'");
        $q->execute([$dbName]);
        return ((int)$q->fetchColumn()) === 1;
    } catch (Throwable $e) {
        return false;
    }
}
