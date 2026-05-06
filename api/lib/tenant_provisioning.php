<?php
// M/2026/05/06/83.3 — Provisioning auto tenant a l activation.
// Aligne pattern V20 : workspaces (slug, type, display_name) + workspace_members
// (workspace_id, user_id, role) + DB ocre_wsp_<slug>(_test) via
// scripts/provision-tenant.sh (sudo NOPASSWD pour www-ocre).
// Sessions ocre_meta.sessions token X-Session-Token, pas cookie cross-subdomain.

if (!function_exists('ocre_provision_tenant_for_user')) {

function _ocre_meta_pdo(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function _ocre_slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower((string)$s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 30);
}

function _ocre_resolve_slug(PDO $meta, string $base): string {
    if ($base === '') return '';
    $st = $meta->prepare("SELECT slug FROM workspaces WHERE slug = ? OR slug LIKE ? FOR UPDATE");
    $st->execute([$base, $base . '-%']);
    $taken = [];
    foreach ($st->fetchAll() as $r) $taken[$r['slug']] = true;
    if (!isset($taken[$base])) return $base;
    for ($i = 2; $i <= 999; $i++) {
        $cand = $base . '-' . $i;
        if (strlen($cand) > 30) $cand = substr($base, 0, 30 - strlen('-' . $i)) . '-' . $i;
        if (!isset($taken[$cand])) return $cand;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

function _ocre_create_session(PDO $meta, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $st = $meta->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?, NOW())"
    );
    $st->execute([$token, $userId, $ip, $ua]);
    return $token;
}

function _ocre_log_provisioning(string $line): void {
    $logFile = '/var/log/ocre/provisioning.log';
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

/**
 * Provisionne le workspace tenant pour un user M83 (role=agent).
 *
 * Idempotent : si user.slug deja set ET workspace_members(role=owner) existe,
 * retourne le slug existant + nouvelle session.
 *
 * @return array ['ok'=>bool, 'slug'=>string, 'session_token'=>string, 'redirect_url'=>string]
 *               ou ['ok'=>false, 'error'=>string, 'detail'=>string]
 */
function ocre_provision_tenant_for_user(int $userId): array {
    $start = microtime(true);
    try {
        $meta = _ocre_meta_pdo();

        $st = $meta->prepare("SELECT id, email, prenom, nom, display_name, slug FROM users WHERE id = ? AND archived_at IS NULL LIMIT 1");
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) {
            _ocre_log_provisioning(sprintf('[%s] user_id=%d FAIL user introuvable', date('c'), $userId));
            return ['ok' => false, 'error' => 'User introuvable', 'detail' => 'id=' . $userId];
        }

        if (!empty($u['slug'])) {
            $chk = $meta->prepare(
                "SELECT w.slug FROM workspaces w
                 JOIN workspace_members m ON m.workspace_id = w.id AND m.role = 'owner' AND m.left_at IS NULL
                 WHERE w.slug = ? AND m.user_id = ? AND w.archived_at IS NULL
                 LIMIT 1"
            );
            $chk->execute([$u['slug'], $userId]);
            $existing = $chk->fetch();
            if ($existing) {
                $sessionToken = _ocre_create_session($meta, $userId);
                $duration = (int)((microtime(true) - $start) * 1000);
                _ocre_log_provisioning(sprintf('[%s] user_id=%d slug=%s duration_ms=%d result=idempotent_session_only',
                    date('c'), $userId, $existing['slug'], $duration));
                return [
                    'ok' => true,
                    'slug' => $existing['slug'],
                    'session_token' => $sessionToken,
                    'redirect_url' => 'https://' . $existing['slug'] . '.ocre.immo/?_s=' . $sessionToken,
                    'idempotent' => true,
                ];
            }
        }

        $base = _ocre_slugify(trim(($u['prenom'] ?? '') . '-' . ($u['nom'] ?? '')));
        if ($base === '' || $base === '-') $base = 'agent-' . $userId;

        $meta->beginTransaction();
        $slug = _ocre_resolve_slug($meta, $base);
        if ($slug === '') {
            $meta->rollBack();
            return ['ok' => false, 'error' => 'Slug invalide', 'detail' => 'base=' . $base];
        }

        $upd = $meta->prepare("UPDATE users SET slug = ? WHERE id = ?");
        $upd->execute([$slug, $userId]);
        $meta->commit();

        $displayName = trim((string)($u['display_name'] ?? ($u['prenom'] . ' ' . $u['nom']))) ?: $slug;
        $cmd = 'sudo -n /opt/ocre-app/scripts/provision-tenant.sh '
            . escapeshellarg($slug)
            . ' --owner-user-id=' . escapeshellarg((string)$userId)
            . ' --display-name=' . escapeshellarg($displayName)
            . ' --type=wsp'
            . ' --country=FR 2>&1';

        $output = [];
        $rc = -1;
        exec($cmd, $output, $rc);
        $outputStr = implode("\n", $output);

        if ($rc !== 0) {
            $rollback = $meta->prepare("UPDATE users SET slug = NULL WHERE id = ?");
            $rollback->execute([$userId]);
            _ocre_log_provisioning(sprintf('[%s] user_id=%d slug=%s FAIL rc=%d output=%s',
                date('c'), $userId, $slug, $rc, mb_substr($outputStr, 0, 500)));
            @shell_exec('/root/bin/notify --project ocre --priority high --title '
                . escapeshellarg('M83.3 provisioning ECHEC')
                . ' --body ' . escapeshellarg('user_id=' . $userId . ' slug=' . $slug . ' rc=' . $rc . ' output=' . substr($outputStr, 0, 200))
                . ' >/dev/null 2>&1 &');
            return ['ok' => false, 'error' => 'Provisioning echoue, support contacte', 'detail' => 'rc=' . $rc];
        }

        $sessionToken = _ocre_create_session($meta, $userId);
        $duration = (int)((microtime(true) - $start) * 1000);
        _ocre_log_provisioning(sprintf('[%s] user_id=%d slug=%s duration_ms=%d result=created',
            date('c'), $userId, $slug, $duration));

        return [
            'ok' => true,
            'slug' => $slug,
            'session_token' => $sessionToken,
            'redirect_url' => 'https://' . $slug . '.ocre.immo/?_s=' . $sessionToken,
            'idempotent' => false,
            'duration_ms' => $duration,
        ];
    } catch (Throwable $e) {
        if (isset($meta) && $meta->inTransaction()) {
            try { $meta->rollBack(); } catch (Throwable $_) {}
        }
        _ocre_log_provisioning(sprintf('[%s] user_id=%d EXCEPTION %s',
            date('c'), $userId, mb_substr($e->getMessage(), 0, 500)));
        @shell_exec('/root/bin/notify --project ocre --priority high --title '
            . escapeshellarg('M83.3 provisioning EXCEPTION')
            . ' --body ' . escapeshellarg('user_id=' . $userId . ' msg=' . substr($e->getMessage(), 0, 200))
            . ' >/dev/null 2>&1 &');
        return ['ok' => false, 'error' => 'Erreur provisioning', 'detail' => $e->getMessage()];
    }
}

}
