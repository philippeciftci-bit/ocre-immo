<?php
// M/2026/05/16/30 — Lib notifs Telegram cycle de vie agent Ocre.
// Pure : aucune dépendance app (shell-out /root/bin/notify), fire-and-forget,
// ne DOIT JAMAIS faire échouer le flow appelant (tout est try/catch + @exec &).
// Évènements : signup 🌱, login 🟢 →, logout 🔴 ←, unsubscribe 🥀, payment 💰.
declare(strict_types=1);

if (!function_exists('nl_emit')) {

// M/2026/05/16/33 — gate on/off par event (table notif_settings, cache 60s, fail-open).
function nl_enabled(string $key): bool {
    static $cache = [];
    static $ts = 0;
    if ((time() - $ts) > 60) { $cache = []; $ts = time(); }
    if (array_key_exists($key, $cache)) return $cache[$key];
    $enabled = true; // fail-open : si réglages indisponibles, on NE supprime PAS la notif.
    try {
        if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS')) {
            $pdo = new \PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2]);
            $st = $pdo->prepare("SELECT enabled FROM notif_settings WHERE event_key = ? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            if ($v !== false) $enabled = ((int)$v === 1);
        }
    } catch (\Throwable $e) { $enabled = true; }
    $cache[$key] = $enabled;
    return $enabled;
}

function nl_emit(string $title, string $body, string $priority = 'info'): void {
    try {
        $cmd = '/root/bin/notify --project ocre --priority ' . escapeshellarg($priority)
             . ' --title ' . escapeshellarg($title)
             . ' --body ' . escapeshellarg($body)
             . ' > /dev/null 2>&1 &';
        @exec($cmd);
    } catch (\Throwable $e) { /* jamais bloquant */ }
}

// "iPad · Safari", "iPhone · Safari", "Mac · Chrome", "Android · Chrome", "PC · Firefox"
function nl_parse_ua(?string $ua): string {
    $ua = (string)$ua;
    if ($ua === '' || $ua === '?') return 'Appareil inconnu';
    $device = 'PC';
    if (preg_match('/iPad/i', $ua))                         $device = 'iPad';
    elseif (preg_match('/iPhone/i', $ua))                   $device = 'iPhone';
    elseif (preg_match('/Android/i', $ua))                  $device = 'Android';
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua))       $device = 'Mac';
    elseif (preg_match('/Windows/i', $ua))                  $device = 'PC';
    elseif (preg_match('/Linux/i', $ua))                    $device = 'Linux';
    $browser = 'navigateur';
    if (preg_match('/Edg\//i', $ua))                        $browser = 'Edge';
    elseif (preg_match('/OPR\/|Opera/i', $ua))              $browser = 'Opera';
    elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg\//i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox\//i', $ua))                $browser = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
    return $device . ' · ' . $browser;
}

function nl_dur(?int $seconds): string {
    $s = max(0, (int)$seconds);
    if ($s < 60)    return $s . ' s';
    if ($s < 3600)  return intdiv($s, 60) . ' min';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}

function _nl_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// 01 — INSCRIPTION (🌱, info)
function notify_signup(string $name, string $email, string $slug, string $phone = ''): void {
    if (!nl_enabled('signup')) return;
    $body = "🌱 <b>" . _nl_h($name) . "</b>\n"
          . _nl_h($email) . "\n"
          . "Espace : <b>" . _nl_h($slug) . "</b>"
          . ($phone !== '' ? "\nTéléphone : " . _nl_h($phone) : "") . "\n"
          . date('d/m/Y H:i');
    nl_emit('Nouvel agent inscrit', $body, 'info');
}

// 02 — CONNEXION (🟢 →, info)
function notify_login(string $name, string $email, ?string $ua, string $ip, string $tokenPrefix): void {
    if (!nl_enabled('login')) return;
    $body = "🟢 → <b>" . _nl_h($name) . "</b>\n"
          . _nl_h($email) . "\n"
          . _nl_h(nl_parse_ua($ua)) . " · " . _nl_h($ip) . "\n"
          . "session " . _nl_h(substr($tokenPrefix, 0, 8)) . " · " . date('d/m/Y H:i');
    nl_emit('Agent connecté', $body, 'info');
}

// 03 — DÉCONNEXION (🔴 ←, info)
function notify_logout(string $name, string $email, string $type, ?int $durationSeconds): void {
    if (!nl_enabled('logout')) return;
    $t = $type === 'auto' ? 'auto (inactivité)' : 'manuel';
    $body = "🔴 ← <b>" . _nl_h($name) . "</b>\n"
          . _nl_h($email) . "\n"
          . "Type : <b>" . _nl_h($t) . "</b> · durée " . _nl_h(nl_dur($durationSeconds)) . "\n"
          . date('d/m/Y H:i');
    nl_emit('Agent déconnecté', $body, 'info');
}

// 04 — DÉSINSCRIPTION (🥀, HIGH) — helper prêt, câblage = mission M/DESINSCRIPTION-FLOW.
function notify_unsubscribe(string $name, string $email, string $slug): void {
    if (!nl_enabled('unsubscribe')) return;
    $body = "🥀 <b>" . _nl_h($name) . "</b>\n"
          . _nl_h($email) . "\n"
          . "Espace archivé : <b>" . _nl_h($slug) . "</b>\n"
          . "RGPD : effacement définitif à J+30 sauf rétractation.\n"
          . date('d/m/Y H:i');
    nl_emit('Agent désinscrit', $body, 'high');
}

// 06 — PAIEMENT (💰, info) — helper prêt, câblage ultérieur.
function notify_payment(string $name, string $email, string $plan, string $amount, string $mode): void {
    if (!nl_enabled('payment')) return;
    $body = "💰 <b>" . _nl_h($name) . "</b>\n"
          . _nl_h($email) . "\n"
          . "Plan : <b>" . _nl_h($plan) . "</b> · " . _nl_h($amount) . " · " . _nl_h($mode) . "\n"
          . date('d/m/Y H:i');
    nl_emit('Paiement abonnement', $body, 'info');
}

} // function_exists guard
