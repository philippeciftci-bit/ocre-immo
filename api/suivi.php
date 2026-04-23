<?php
// Ocre v18.1 — module Suivi (todos + events + journal). Sécurité : user_id = session, client.user_id = user_id.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int)$user['id'];

function ownClient($pdo, $uid, $client_id) {
    if (!$client_id) return true; // todos sans client = OK
    $st = $pdo->prepare("SELECT 1 FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
    $st->execute([(int)$client_id, $uid]);
    return (bool)$st->fetchColumn();
}

function clientName($pdo, $client_id) {
    if (!$client_id) return null;
    $st = $pdo->prepare("SELECT prenom, nom, societe_nom FROM clients WHERE id = ? LIMIT 1");
    $st->execute([(int)$client_id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!empty($r['societe_nom'])) return $r['societe_nom'];
    return trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: null;
}

$pdo = db();

switch ($action) {

    // ─── TODOS ─────────────────────────────────────────────────
    case 'list_todos': {
        $client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        if ($client_id) {
            if (!ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
            $st = $pdo->prepare(
                "SELECT * FROM suivi_todos WHERE user_id = ? AND client_id = ?
                 ORDER BY done ASC, FIELD(priority,'high','medium','low'),
                          (due_at IS NULL) ASC, due_at ASC"
            );
            $st->execute([$uid, $client_id]);
        } else {
            $st = $pdo->prepare(
                "SELECT * FROM suivi_todos WHERE user_id = ?
                 ORDER BY done ASC, FIELD(priority,'high','medium','low'),
                          (due_at IS NULL) ASC, due_at ASC"
            );
            $st->execute([$uid]);
        }
        jsonOk(['todos' => $st->fetchAll()]);
    }

    case 'add_todo': {
        $client_id = isset($input['client_id']) ? (int)$input['client_id'] : null;
        $title = substr(trim((string)($input['title'] ?? '')), 0, 255);
        if ($title === '') jsonError('title requis');
        $due = $input['due_at'] ?? null;
        $priority = in_array($input['priority'] ?? 'medium', ['low','medium','high'], true) ? $input['priority'] : 'medium';
        if ($client_id && !ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
        $st = $pdo->prepare(
            "INSERT INTO suivi_todos (client_id, user_id, title, due_at, priority)
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([$client_id ?: null, $uid, $title, $due ?: null, $priority]);
        $id = (int)$pdo->lastInsertId();
        jsonOk(['id' => $id]);
    }

    case 'toggle_todo': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $done = !empty($input['done']) ? 1 : 0;
        $st = $pdo->prepare("UPDATE suivi_todos SET done = ? WHERE id = ? AND user_id = ?");
        $st->execute([$done, $id, $uid]);
        jsonOk(['id' => $id, 'done' => (bool)$done]);
    }

    case 'update_todo': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $title = isset($input['title']) ? substr(trim((string)$input['title']), 0, 255) : null;
        $due = array_key_exists('due_at', $input) ? ($input['due_at'] ?: null) : null;
        $priority = isset($input['priority']) && in_array($input['priority'], ['low','medium','high'], true) ? $input['priority'] : null;
        $sets = []; $vals = [];
        if ($title !== null) { $sets[] = 'title = ?'; $vals[] = $title; }
        if (array_key_exists('due_at', $input)) { $sets[] = 'due_at = ?'; $vals[] = $due; }
        if ($priority !== null) { $sets[] = 'priority = ?'; $vals[] = $priority; }
        if (!$sets) jsonError('rien à mettre à jour');
        $vals[] = $id; $vals[] = $uid;
        $st = $pdo->prepare("UPDATE suivi_todos SET " . implode(',', $sets) . " WHERE id = ? AND user_id = ?");
        $st->execute($vals);
        jsonOk(['id' => $id]);
    }

    case 'delete_todo': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $st = $pdo->prepare("DELETE FROM suivi_todos WHERE id = ? AND user_id = ?");
        $st->execute([$id, $uid]);
        jsonOk(['deleted' => $id]);
    }

    // ─── EVENTS ────────────────────────────────────────────────
    case 'list_events': {
        $client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $sql = "SELECT * FROM suivi_events WHERE user_id = ?";
        $vals = [$uid];
        if ($client_id) {
            if (!ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
            $sql .= " AND client_id = ?"; $vals[] = $client_id;
        }
        if ($from) { $sql .= " AND when_at >= ?"; $vals[] = $from; }
        if ($to)   { $sql .= " AND when_at <= ?"; $vals[] = $to; }
        $sql .= " ORDER BY when_at ASC";
        $st = $pdo->prepare($sql);
        $st->execute($vals);
        jsonOk(['events' => $st->fetchAll()]);
    }

    case 'add_event': {
        $client_id = isset($input['client_id']) ? (int)$input['client_id'] : null;
        $type = in_array($input['type'] ?? 'rdv', ['rdv','appel','visite','email','autre'], true) ? $input['type'] : 'rdv';
        $title = substr(trim((string)($input['title'] ?? '')), 0, 255);
        $when = $input['when_at'] ?? null;
        if ($title === '' || !$when) jsonError('title + when_at requis');
        $duration = (int)($input['duration_min'] ?? 60);
        $location = substr((string)($input['location'] ?? ''), 0, 255);
        $notes = (string)($input['notes'] ?? '');
        $reminder = (int)($input['reminder_min_before'] ?? 60);
        if ($client_id && !ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
        $st = $pdo->prepare(
            "INSERT INTO suivi_events (client_id, user_id, type, title, when_at, duration_min, location, notes, reminder_min_before)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([$client_id ?: null, $uid, $type, $title, $when, $duration, $location, $notes, $reminder]);
        $id = (int)$pdo->lastInsertId();
        jsonOk(['id' => $id]);
    }

    case 'update_event': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $sets = []; $vals = [];
        $allowed = ['type','title','when_at','duration_min','location','notes','reminder_min_before','status'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $input)) {
                $v = $input[$k];
                if ($k === 'type' && !in_array($v, ['rdv','appel','visite','email','autre'], true)) continue;
                if ($k === 'status' && !in_array($v, ['planned','done','cancelled'], true)) continue;
                $sets[] = "$k = ?"; $vals[] = $v;
            }
        }
        if (!$sets) jsonError('rien à mettre à jour');
        // Reset notified si when_at change.
        if (in_array('when_at = ?', $sets, true)) { $sets[] = "notified = 0"; }
        $vals[] = $id; $vals[] = $uid;
        $st = $pdo->prepare("UPDATE suivi_events SET " . implode(',', $sets) . " WHERE id = ? AND user_id = ?");
        $st->execute($vals);
        jsonOk(['id' => $id]);
    }

    case 'delete_event': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $st = $pdo->prepare("DELETE FROM suivi_events WHERE id = ? AND user_id = ?");
        $st->execute([$id, $uid]);
        jsonOk(['deleted' => $id]);
    }

    case 'complete_event': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $st = $pdo->prepare("SELECT * FROM suivi_events WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$id, $uid]);
        $ev = $st->fetch();
        if (!$ev) jsonError('Introuvable', 404);
        $pdo->prepare("UPDATE suivi_events SET status = 'done' WHERE id = ?")->execute([$id]);
        // Auto-add journal entry si client lié.
        if (!empty($ev['client_id'])) {
            $kind_map = ['rdv'=>'note','appel'=>'appel_sortant','visite'=>'visite','email'=>'email_envoye','autre'=>'note'];
            $kind = $kind_map[$ev['type']] ?? 'note';
            $content = "✓ " . $ev['title'] . ($ev['notes'] ? "\n" . $ev['notes'] : '');
            $j = $pdo->prepare(
                "INSERT INTO suivi_journal (client_id, user_id, ts, kind, content) VALUES (?, ?, NOW(), ?, ?)"
            );
            $j->execute([$ev['client_id'], $uid, $kind, $content]);
        }
        jsonOk(['id' => $id]);
    }

    // ─── JOURNAL ───────────────────────────────────────────────
    case 'list_journal': {
        $client_id = (int)($_GET['client_id'] ?? 0);
        if (!$client_id) jsonError('client_id requis');
        if (!ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
        $st = $pdo->prepare(
            "SELECT * FROM suivi_journal WHERE user_id = ? AND client_id = ?
             ORDER BY ts DESC LIMIT 200"
        );
        $st->execute([$uid, $client_id]);
        jsonOk(['journal' => $st->fetchAll()]);
    }

    case 'add_journal_entry': {
        $client_id = (int)($input['client_id'] ?? 0);
        if (!$client_id) jsonError('client_id requis');
        if (!ownClient($pdo, $uid, $client_id)) jsonError('Accès refusé', 403);
        $kind = in_array($input['kind'] ?? 'note', ['note','appel_entrant','appel_sortant','email_envoye','email_recu','visite','sms'], true) ? $input['kind'] : 'note';
        $content = trim((string)($input['content'] ?? ''));
        if ($content === '') jsonError('content requis');
        $ts = $input['ts'] ?? date('Y-m-d H:i:s');
        $st = $pdo->prepare(
            "INSERT INTO suivi_journal (client_id, user_id, ts, kind, content) VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([$client_id, $uid, $ts, $kind, $content]);
        jsonOk(['id' => (int)$pdo->lastInsertId()]);
    }

    case 'delete_journal_entry': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $st = $pdo->prepare("DELETE FROM suivi_journal WHERE id = ? AND user_id = ?");
        $st->execute([$id, $uid]);
        jsonOk(['deleted' => $id]);
    }

    // ─── UPCOMING (events 7j + todos dues) ─────────────────────
    case 'list_upcoming': {
        $st = $pdo->prepare(
            "SELECT 'event' AS kind, id, client_id, type, title, when_at AS at, duration_min, location, status
             FROM suivi_events
             WHERE user_id = ? AND status = 'planned'
               AND when_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
             ORDER BY when_at ASC LIMIT 50"
        );
        $st->execute([$uid]);
        $events = $st->fetchAll();
        $st2 = $pdo->prepare(
            "SELECT 'todo' AS kind, id, client_id, NULL AS type, title, due_at AS at, NULL AS duration_min, NULL AS location, NULL AS status, priority
             FROM suivi_todos
             WHERE user_id = ? AND done = 0
               AND due_at IS NOT NULL
               AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
             ORDER BY due_at ASC LIMIT 50"
        );
        $st2->execute([$uid]);
        $todos = $st2->fetchAll();
        jsonOk(['events' => $events, 'todos' => $todos]);
    }

    // ─── ICS export ────────────────────────────────────────────
    case 'ics_event': {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); exit('id requis'); }
        $st = $pdo->prepare("SELECT * FROM suivi_events WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$id, $uid]);
        $ev = $st->fetch();
        if (!$ev) { http_response_code(404); exit('Introuvable'); }
        $cname = clientName($pdo, $ev['client_id']);
        $start = strtotime($ev['when_at']);
        $end = $start + max(15, (int)$ev['duration_min']) * 60;
        $fmt = fn($t) => gmdate('Ymd\THis\Z', $t);
        $dtstamp = gmdate('Ymd\THis\Z', strtotime($ev['created_at'] ?? 'now'));
        $esc = function($s) {
            return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], (string)$s);
        };
        $summary = $esc($ev['title'] . ($cname ? ' — ' . $cname : ''));
        $location = $esc($ev['location'] ?? '');
        $desc_parts = [];
        if (!empty($ev['notes'])) $desc_parts[] = $ev['notes'];
        if ($cname) $desc_parts[] = 'Dossier : ' . $cname;
        $desc_parts[] = APP_URL . '#client/' . (int)$ev['client_id'] . '?section=4';
        $description = $esc(implode("\n", $desc_parts));
        $reminder = max(0, (int)$ev['reminder_min_before']);
        $alarm = '';
        if ($reminder > 0) {
            $alarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT{$reminder}M\r\nDESCRIPTION:" . $summary . "\r\nEND:VALARM\r\n";
        }
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Ocre Immo//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
             . "BEGIN:VEVENT\r\n"
             . "UID:ocre-event-{$id}@ocre.immo\r\n"
             . "DTSTAMP:{$dtstamp}\r\n"
             . "DTSTART:" . $fmt($start) . "\r\n"
             . "DTEND:" . $fmt($end) . "\r\n"
             . "SUMMARY:{$summary}\r\n"
             . "LOCATION:{$location}\r\n"
             . "DESCRIPTION:{$description}\r\n"
             . $alarm
             . "END:VEVENT\r\n"
             . "END:VCALENDAR\r\n";
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="ocre-rdv-' . $id . '.ics"');
        echo $ics;
        exit;
    }

    default:
        jsonError('Action inconnue', 404);
}
