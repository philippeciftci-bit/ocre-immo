<?php
// M118 — Helper calendar : table rendez_vous + ICS RFC 5545 generator + signed JWT feed.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/channels/ChannelDriver.php'; // not used, kept for path coherence
// JWT helper réutilisé depuis auth (mais auth est sur /opt/ocre-auth/, on duplique helper minimal ici).

const CAL_FEED_SECRET_PATH = '/root/.secrets/ocre_jwt_secret';

function cal_tenant_pdo(string $tenant): PDO {
    static $cache = [];
    if (isset($cache[$tenant])) return $cache[$tenant];
    $cache[$tenant] = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_wsp_' . $tenant . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $cache[$tenant];
}

function cal_ensure_schema(string $tenant): void {
    $pdo = cal_tenant_pdo($tenant);
    $pdo->exec("CREATE TABLE IF NOT EXISTS rendez_vous (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dossier_id INT UNSIGNED NULL,
        type ENUM('visite','signature','mandat','equipe','autre') NOT NULL DEFAULT 'visite',
        titre VARCHAR(255) NOT NULL,
        description TEXT NULL,
        lieu VARCHAR(255) NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        attendees JSON NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_start (start_at),
        INDEX idx_dossier (dossier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// JWT helper minimal pour signed feed.
function cal_jwt_secret(): string {
    static $s = null;
    if ($s !== null) return $s;
    $s = is_readable(CAL_FEED_SECRET_PATH) ? trim(file_get_contents(CAL_FEED_SECRET_PATH)) : 'fallback_dev_secret_change_in_prod_64chars_xxxxxxxxxxxxxxxxxxxxxxxx';
    return $s;
}

function cal_b64u_encode(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function cal_b64u_decode(string $d): string { $p = strlen($d) % 4; if ($p) $d .= str_repeat('=', 4 - $p); return base64_decode(strtr($d, '-_', '+/')); }

function cal_make_feed_token(string $tenant, int $userId): string {
    $h = cal_b64u_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = cal_b64u_encode(json_encode(['sub' => $userId, 'tenant' => $tenant, 'iat' => time(), 'scope' => 'calendar_feed']));
    $s = cal_b64u_encode(hash_hmac('sha256', "$h.$p", cal_jwt_secret(), true));
    return "$h.$p.$s";
}

function cal_verify_feed_token(string $token): ?array {
    if (substr_count($token, '.') !== 2) return null;
    [$h, $p, $s] = explode('.', $token);
    $expected = cal_b64u_encode(hash_hmac('sha256', "$h.$p", cal_jwt_secret(), true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(cal_b64u_decode($p), true);
    if (!is_array($payload) || ($payload['scope'] ?? '') !== 'calendar_feed') return null;
    return $payload;
}

function cal_ics_escape(string $s): string {
    return str_replace([',', ';', "\n", "\r"], ['\\,', '\\;', '\\n', ''], $s);
}

function cal_dt_utc(string $datetime): string {
    $dt = new DateTime($datetime, new DateTimeZone('Europe/Paris'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}

// Genere ICS RFC 5545 pour les RDV des 90 prochains jours du tenant.
function cal_generate_ics(string $tenant, int $userId): string {
    cal_ensure_schema($tenant);
    $pdo = cal_tenant_pdo($tenant);
    $st = $pdo->prepare("SELECT * FROM rendez_vous WHERE start_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 90 DAY) ORDER BY start_at");
    $st->execute();
    $events = $st->fetchAll();

    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Ocre Immo//Oi Agent//FR\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "X-WR-CALNAME:Ocre Immo · " . $tenant . "\r\n";
    $ics .= "X-WR-TIMEZONE:Europe/Paris\r\n";

    foreach ($events as $e) {
        $uid = 'ocre-rdv-' . $e['id'] . '-' . $tenant . '@ocre.immo';
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "\r\n";
        $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        $ics .= "DTSTART:" . cal_dt_utc($e['start_at']) . "\r\n";
        $ics .= "DTEND:" . cal_dt_utc($e['end_at']) . "\r\n";
        $ics .= "SUMMARY:" . cal_ics_escape('[' . strtoupper($e['type']) . '] ' . $e['titre']) . "\r\n";
        if (!empty($e['description'])) $ics .= "DESCRIPTION:" . cal_ics_escape($e['description']) . "\r\n";
        if (!empty($e['lieu'])) $ics .= "LOCATION:" . cal_ics_escape($e['lieu']) . "\r\n";
        $ics .= "ORGANIZER;CN=Ocre Immo:mailto:noreply@ocre.immo\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "END:VEVENT\r\n";
    }

    $ics .= "END:VCALENDAR\r\n";
    return $ics;
}
