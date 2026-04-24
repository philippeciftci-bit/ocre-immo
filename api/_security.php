<?php
// V18.39 — utilitaires sécurité partagés par tous les endpoints critiques.
// - ensureSecuritySchema() : tables rate_limits, login_attempts, access_logs.
// - checkRateLimit($endpoint, $limit, $window_sec, $scope_key=null) : retourne rien si OK,
//   envoie 429 + Retry-After + exit si dépassement. Scope par user OR par IP si pas auth.
// - checkLoginLockout($email) : throw 429 si lockout actif.
// - recordLoginAttempt($email, $ip, $success) : MAJ compteur.
// - logAccess($user_id, $action, $meta_array) : insert access_logs.
// - purgeAccessLogs() : DELETE > 90 j, à appeler depuis un cron.

if (!defined('OCRE_SECURITY_LOADED')) {
    define('OCRE_SECURITY_LOADED', 1);
    // Lockout : 5 échecs → 15 min (quota login).
    define('LOGIN_MAX_FAILS', 5);
    define('LOGIN_LOCK_SEC', 900);
    // Quota photos : 100 photos / dossier, 500 Mo / user.
    define('PHOTOS_MAX_PER_DOSSIER', 100);
    define('PHOTOS_MAX_BYTES_PER_USER', 500 * 1024 * 1024);

    function ensureSecuritySchema(): void {
        static $done = false;
        if ($done) return;
        $pdo = db();
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scope_key VARCHAR(120) NOT NULL,      -- 'u:123' ou 'ip:1.2.3.4'
                endpoint VARCHAR(60) NOT NULL,
                window_start DATETIME NOT NULL,
                count INT NOT NULL DEFAULT 1,
                INDEX idx_scope_ep_win (scope_key, endpoint, window_start)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                count INT NOT NULL DEFAULT 1,
                locked_until DATETIME NULL,
                last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_email_ip (email, ip),
                INDEX idx_email (email),
                INDEX idx_lockuntil (locked_until)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS access_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                action VARCHAR(60) NOT NULL,
                endpoint VARCHAR(120) NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                meta JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_action (action),
                INDEX idx_created (created_at)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {}
        $done = true;
    }

    function _clientIp(): string {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    function _scopeKey(?int $user_id = null): string {
        if ($user_id) return 'u:' . $user_id;
        return 'ip:' . _clientIp();
    }

    /**
     * Fenêtre glissante naïve : on compte les rows dans (NOW - window_sec).
     * Retourne void si OK, envoie 429 + exit si dépassé.
     */
    function checkRateLimit(string $endpoint, int $limit, int $window_sec, ?int $user_id = null): void {
        ensureSecuritySchema();
        $pdo = db();
        $scope = _scopeKey($user_id);
        $cutoff = date('Y-m-d H:i:s', time() - $window_sec);

        // Purge des rows anciens pour la scope (évite le ballonnement).
        try {
            $del = $pdo->prepare("DELETE FROM rate_limits WHERE scope_key = ? AND endpoint = ? AND window_start < ?");
            $del->execute([$scope, $endpoint, $cutoff]);
        } catch (Exception $e) {}

        // Compte les hits récents.
        $st = $pdo->prepare("SELECT COALESCE(SUM(count), 0) n FROM rate_limits WHERE scope_key = ? AND endpoint = ? AND window_start >= ?");
        $st->execute([$scope, $endpoint, $cutoff]);
        $n = (int) $st->fetch(PDO::FETCH_ASSOC)['n'];

        if ($n >= $limit) {
            header('Retry-After: ' . $window_sec);
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Rate limit atteint pour ' . $endpoint . ' (' . $limit . ' / ' . $window_sec . 's)',
                'retry_after_sec' => $window_sec,
            ]);
            exit;
        }

        // Incrémente (nouvelle row = 1 hit).
        try {
            $ins = $pdo->prepare("INSERT INTO rate_limits (scope_key, endpoint, window_start, count) VALUES (?, ?, NOW(), 1)");
            $ins->execute([$scope, $endpoint]);
        } catch (Exception $e) {}
    }

    /** Retourne null si pas locké, sinon timestamp unix fin de lock. */
    function checkLoginLockout(string $email): ?int {
        ensureSecuritySchema();
        $st = db()->prepare("SELECT locked_until FROM login_attempts WHERE email = ? AND ip = ? LIMIT 1");
        $st->execute([$email, _clientIp()]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['locked_until']) return null;
        $ts = strtotime((string) $r['locked_until']);
        if ($ts && $ts > time()) return $ts;
        return null;
    }

    function recordLoginAttempt(string $email, bool $success): void {
        ensureSecuritySchema();
        $pdo = db();
        $ip = _clientIp();
        if ($success) {
            // Reset compteur + locked_until pour (email, ip).
            try {
                $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND ip = ?")->execute([$email, $ip]);
            } catch (Exception $e) {}
            return;
        }
        // Fail : upsert compteur.
        try {
            $st = $pdo->prepare("SELECT id, count FROM login_attempts WHERE email = ? AND ip = ? LIMIT 1");
            $st->execute([$email, $ip]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $newCount = (int) $r['count'] + 1;
                $locked = ($newCount >= LOGIN_MAX_FAILS) ? date('Y-m-d H:i:s', time() + LOGIN_LOCK_SEC) : null;
                $up = $pdo->prepare("UPDATE login_attempts SET count = ?, locked_until = ?, last_attempt_at = NOW() WHERE id = ?");
                $up->execute([$newCount, $locked, $r['id']]);
            } else {
                $pdo->prepare("INSERT INTO login_attempts (email, ip, count, last_attempt_at) VALUES (?, ?, 1, NOW())")
                    ->execute([$email, $ip]);
            }
        } catch (Exception $e) {}
    }

    function logAccess(?int $user_id, string $action, ?array $meta = null): void {
        ensureSecuritySchema();
        try {
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $endpoint = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120);
            $ip = _clientIp();
            $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $st = db()->prepare(
                "INSERT INTO access_logs (user_id, action, endpoint, ip, user_agent, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $st->execute([$user_id, $action, $endpoint, $ip, $ua, $metaJson]);
        } catch (Exception $e) { /* silent */ }
    }

    function purgeOldAccessLogs(int $days = 90): int {
        ensureSecuritySchema();
        try {
            $st = db()->prepare("DELETE FROM access_logs WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $st->execute([$days]);
            return $st->rowCount();
        } catch (Exception $e) { return 0; }
    }

    /** Chemin base uploads (aligne sur image.php v18.18). */
    function uploadsBaseDir(): string {
        return realpath(__DIR__ . '/..') . '/uploads';
    }

    /**
     * Vérifie quotas avant upload. Retourne void si OK, envoie 413 + exit sinon.
     * $dossier_photo_count : count existant dans ce dossier (pour check N+1).
     * $user_id : user courant.
     * $new_bytes : taille du fichier entrant (pour check total+new).
     */
    function checkPhotoQuota(int $user_id, int $dossier_photo_count, int $new_bytes): void {
        if ($dossier_photo_count + 1 > PHOTOS_MAX_PER_DOSSIER) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Quota photos atteint pour ce dossier (' . PHOTOS_MAX_PER_DOSSIER . ' max).',
            ]);
            exit;
        }
        // Total bytes dans /uploads/users/user_X/
        $userDir = uploadsBaseDir() . '/users/user_' . $user_id;
        $totalBytes = 0;
        if (is_dir($userDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) $totalBytes += $f->getSize();
            }
        }
        if ($totalBytes + $new_bytes > PHOTOS_MAX_BYTES_PER_USER) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Quota stockage photos atteint (' . round(PHOTOS_MAX_BYTES_PER_USER / 1048576) . ' Mo max par utilisateur).',
                'current_mb' => round($totalBytes / 1048576, 1),
            ]);
            exit;
        }
    }
}
