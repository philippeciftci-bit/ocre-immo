<?php
// M/2026/05/11/28 — Stats Vue d'ensemble : sparkline signups 7j + counts coherents avec sections.
//   GET ?action=stats → { signups_7d: [{day,count}], counts: { users, sessions_active, magic_pending } }
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function os_out(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') os_out(['ok' => false, 'error' => 'super_admin only'], 403);

require_once __DIR__ . '/db.php';
$meta = pdo_meta();

// Auto-purge cohérent avec la page Sessions / Magic (silencieux).
try {
    $meta->exec("DELETE FROM sessions WHERE expires_at < NOW()");
    $meta->exec("DELETE FROM auth_sessions WHERE expires_at < NOW()");
    $meta->exec("DELETE FROM auth_magic_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
} catch (Throwable $e) { /* swallow */ }

// Signups 7 derniers jours (auth_users.created_at)
$signups = [];
try {
    $st = $meta->query(
        "SELECT DATE(created_at) AS day, COUNT(*) AS count
         FROM auth_users
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(created_at) ORDER BY day"
    );
    $byDay = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byDay[$r['day']] = (int) $r['count'];
    // Comble les jours manquants (0 signups) pour avoir 7 points alignés
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $signups[] = ['day' => $d, 'count' => $byDay[$d] ?? 0];
    }
} catch (Throwable $e) { $signups = []; }

// Counts unifiés (cohérent avec page Sessions / Magic / Users)
$counts = [];
try {
    $counts['users_total'] = (int) $meta->query("SELECT COUNT(*) FROM auth_users")->fetchColumn();
    // Sessions actives = même source que page Sessions (sessions legacy + auth_sessions)
    $counts['sessions_legacy_active'] = (int) $meta->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn();
    $counts['sessions_auth_active'] = (int) $meta->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn();
    $counts['sessions_active_total'] = $counts['sessions_legacy_active'] + $counts['sessions_auth_active'];
    $counts['magic_pending'] = (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens WHERE used_at IS NULL AND expires_at > NOW()")->fetchColumn();
    $counts['signups_24h'] = (int) $meta->query("SELECT COUNT(*) FROM auth_users WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $counts['signups_7d'] = array_sum(array_column($signups, 'count'));
} catch (Throwable $e) { /* swallow */ }

os_out(['ok' => true, 'signups_7d' => $signups, 'counts' => $counts, 'generated_at' => date('c')]);
