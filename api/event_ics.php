<?php
// M/2026/04/28/57 — Export .ics 1 event ou plage events ou subscription URL.
// RFC 5545 strict : UTF-8, CRLF line endings, escape spéciaux.
require_once __DIR__ . '/db.php';

function ics_escape(string $s): string {
    $s = str_replace(["\\", "\n", "\r", ",", ";"], ["\\\\", "\\n", "", "\\,", "\\;"], $s);
    return $s;
}
function ics_fold(string $line): string {
    // RFC 5545 : lignes max 75 octets, fold avec CRLF + space.
    if (strlen($line) <= 75) return $line;
    $out = '';
    $cur = $line;
    while (strlen($cur) > 75) {
        $out .= substr($cur, 0, 75) . "\r\n ";
        $cur = substr($cur, 75);
    }
    $out .= $cur;
    return $out;
}
function ics_dt(?string $sql): string {
    if (!$sql) return '';
    $ts = strtotime($sql);
    if (!$ts) return '';
    return gmdate('Ymd\THis\Z', $ts);
}

function build_ics_lines(array $events, array $clientById, string $tenantHost, ?array $organizer = null): string {
    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//Ocre Immo//FR';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';
    foreach ($events as $ev) {
        if (empty($ev['scheduled_at'])) continue;
        $dtStart = ics_dt($ev['scheduled_at']);
        if (!$dtStart) continue;
        $startTs = strtotime($ev['scheduled_at']);
        $duration = 3600; // 1h par défaut
        $dtEnd = gmdate('Ymd\THis\Z', $startTs + $duration);
        $cli = $clientById[(int) $ev['client_id']] ?? null;
        $cliName = $cli ? trim(($cli['prenom'] ?? '') . ' ' . ($cli['nom'] ?? '')) : '';
        if (!$cliName && $cli) $cliName = $cli['societe_nom'] ?? '';
        $typeIcon = ['appel'=>'📞','rdv'=>'📅','document'=>'📄','note'=>'📝'][$ev['type']] ?? '';
        $summary = trim(($typeIcon ? "$typeIcon " : '') . $ev['title']);
        $description = trim($ev['description'] ?? '');
        if ($cliName) $description .= ($description ? "\n" : '') . "Dossier : $cliName";
        $url = "https://{$tenantHost}/dossier/" . (int) $ev['client_id'];
        $description .= "\nLien : $url";

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = ics_fold('UID:event-' . (int) $ev['id'] . '@ocre.immo');
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . $dtStart;
        $lines[] = 'DTEND:' . $dtEnd;
        $lines[] = ics_fold('SUMMARY:' . ics_escape($summary));
        $lines[] = ics_fold('DESCRIPTION:' . ics_escape($description));
        if (!empty($ev['address']) || (isset($cli) && !empty($cli['address']))) {
            $loc = $ev['address'] ?? ($cli['address'] ?? '');
            $lines[] = ics_fold('LOCATION:' . ics_escape($loc));
        }
        if ($organizer) {
            $cn = $organizer['display_name'] ?? $organizer['email'] ?? '';
            $lines[] = ics_fold('ORGANIZER;CN=' . ics_escape((string) $cn) . ':mailto:' . ($organizer['email'] ?? ''));
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = ics_fold('URL:' . $url);
        if (!empty($ev['reminder_offset_minutes']) && (int) $ev['reminder_offset_minutes'] > 0) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT' . (int) $ev['reminder_offset_minutes'] . 'M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = ics_fold('DESCRIPTION:Rappel: ' . ics_escape($ev['title']));
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

// Route : event_id (single) | from/to (range) | sub_token (subscription)
$mode = 'single';
$subToken = $_GET['sub_token'] ?? '';
if ($subToken) $mode = 'subscription';
elseif (isset($_GET['from']) || isset($_GET['to'])) $mode = 'range';

if ($mode === 'subscription') {
    // Pas d'auth via session ici : token signé HMAC contient uid.
    $key = is_readable('/root/.secrets/ocre_dev_key') ? trim((string) file_get_contents('/root/.secrets/ocre_dev_key')) : '';
    if (!$key) { http_response_code(500); echo 'no key'; exit; }
    $parts = explode('.', $subToken, 2);
    if (count($parts) !== 2) { http_response_code(400); echo 'invalid token'; exit; }
    [$uid, $sig] = $parts;
    $expected = substr(hash_hmac('sha256', "calsub:{$uid}", $key), 0, 32);
    if (!hash_equals($expected, $sig)) { http_response_code(403); echo 'bad signature'; exit; }
    $uidInt = (int) $uid;

    // Pour subscription : tous events futurs de l'agent dans le tenant courant.
    $st = db()->prepare(
        "SELECT e.* FROM events e JOIN clients c ON c.id = e.client_id
         WHERE e.owner_user_id = ? AND e.scheduled_at > NOW() - INTERVAL 7 DAY
         AND e.status NOT IN ('annule')"
    );
    $st->execute([$uidInt]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC);
    $clientIds = array_unique(array_column($events, 'client_id'));
    $clientById = [];
    if ($clientIds) {
        $in = implode(',', array_map('intval', $clientIds));
        $cs = db()->query("SELECT id, prenom, nom, societe_nom FROM clients WHERE id IN ($in)");
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $clientById[(int) $c['id']] = $c;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'zefk.ocre.immo';
    $ics = build_ics_lines($events, $clientById, $host);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="ocre-calendar-sub.ics"');
    echo $ics;
    exit;
}

// Mode single ou range : auth requise.
$user = requireAuth();
$uid = (int) $user['id'];

if ($mode === 'single') {
    $eventId = (int) ($_GET['event_id'] ?? 0);
    if (!$eventId) { http_response_code(400); echo 'event_id requis'; exit; }
    $st = db()->prepare("SELECT * FROM events WHERE id = ? AND owner_user_id = ? LIMIT 1");
    $st->execute([$eventId, $uid]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ev) { http_response_code(404); echo 'event introuvable'; exit; }
    $cs = db()->prepare("SELECT id, prenom, nom, societe_nom FROM clients WHERE id = ?");
    $cs->execute([$ev['client_id']]);
    $cliRow = $cs->fetch(PDO::FETCH_ASSOC);
    $clientById = $cliRow ? [(int) $cliRow['id'] => $cliRow] : [];
    $host = $_SERVER['HTTP_HOST'] ?? 'zefk.ocre.immo';
    $ics = build_ics_lines([$ev], $clientById, $host, $user);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="event-' . $eventId . '.ics"');
    echo $ics;
    exit;
}

// Mode range
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+90 days'));
$st = db()->prepare(
    "SELECT * FROM events WHERE owner_user_id = ? AND scheduled_at BETWEEN ? AND ? AND status NOT IN ('annule')"
);
$st->execute([$uid, $from . ' 00:00:00', $to . ' 23:59:59']);
$events = $st->fetchAll(PDO::FETCH_ASSOC);
$clientIds = array_unique(array_column($events, 'client_id'));
$clientById = [];
if ($clientIds) {
    $in = implode(',', array_map('intval', $clientIds));
    $cs = db()->query("SELECT id, prenom, nom, societe_nom FROM clients WHERE id IN ($in)");
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $clientById[(int) $c['id']] = $c;
}
$host = $_SERVER['HTTP_HOST'] ?? 'zefk.ocre.immo';
$ics = build_ics_lines($events, $clientById, $host, $user);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="ocre-events-' . $from . '-' . $to . '.ics"');
echo $ics;
