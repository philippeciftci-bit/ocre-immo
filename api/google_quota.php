<?php
// M/2026/04/30/7 — Workflow demande/approbation extension quota Google Places.
// Endpoints :
//   GET  ?action=my_status       -> quota courant de l agent + derniere demande
//   GET  ?action=list             -> super-admin only : toutes demandes (pending par defaut)
//   GET  ?action=usage_list       -> super-admin only : conso jour/mois par agent
//   POST ?action=request          -> agent : creer demande {motif?}
//   POST ?action=approve          -> super-admin : {request_id, response_message, granted_extra?}
//   POST ?action=deny             -> super-admin : {request_id, response_message}
//   POST ?action=set_global_limit -> super-admin : {limit}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
$user = requireAuth();

function ensureGoogleQuotaSchema() {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS google_places_quota (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        date DATE NOT NULL,
        count INT NOT NULL DEFAULT 0,
        daily_limit INT NOT NULL DEFAULT 10,
        UNIQUE KEY uniq_agent_date (agent_id, date),
        INDEX idx_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS google_quota_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        motif TEXT NULL,
        status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
        responded_at DATETIME NULL,
        responder_id INT NULL,
        response_message TEXT NULL,
        granted_extra INT NOT NULL DEFAULT 10,
        INDEX idx_agent_status (agent_id, status),
        INDEX idx_status_date (status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
ensureGoogleQuotaSchema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$casaTz = new DateTimeZone('Africa/Casablanca');
$today = (new DateTime('now', $casaTz))->format('Y-m-d');
$globalLimit = (int) getSetting('google_places_daily_limit', 10);
if ($globalLimit <= 0) $globalLimit = 10;

$isSuperAdmin = (($user['role'] ?? '') === 'super_admin');

function getInput() {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function notifyTelegram($args) {
    @exec('/root/bin/notify ' . $args . ' >/dev/null 2>&1 &');
}

function quotaForAgent($agentId, $today, $globalLimit) {
    $st = db()->prepare("SELECT count, daily_limit FROM google_places_quota WHERE agent_id = ? AND date = ? LIMIT 1");
    $st->execute([$agentId, $today]);
    $r = $st->fetch();
    if (!$r) return ['count' => 0, 'limit' => $globalLimit, 'remaining' => $globalLimit];
    $count = (int)$r['count']; $limit = (int)$r['daily_limit'];
    return ['count' => $count, 'limit' => $limit, 'remaining' => max(0, $limit - $count)];
}

if ($method === 'GET' && $action === 'my_status') {
    $q = quotaForAgent((int)$user['id'], $today, $globalLimit);
    $st = db()->prepare("SELECT id, status, requested_at, motif, response_message, responded_at, granted_extra
                          FROM google_quota_requests WHERE agent_id = ?
                          ORDER BY requested_at DESC LIMIT 1");
    $st->execute([(int)$user['id']]);
    $last = $st->fetch() ?: null;
    jsonOk(['quota' => $q, 'last_request' => $last, 'global_limit' => $globalLimit]);
}

if ($method === 'POST' && $action === 'request') {
    $in = getInput();
    $motif = trim((string)($in['motif'] ?? ''));
    if (mb_strlen($motif) > 1000) $motif = mb_substr($motif, 0, 1000);

    // Anti-spam basique : 1 demande pending max par agent.
    $chk = db()->prepare("SELECT COUNT(*) FROM google_quota_requests WHERE agent_id = ? AND status = 'pending'");
    $chk->execute([(int)$user['id']]);
    if ((int)$chk->fetchColumn() > 0) {
        jsonError('Une demande est deja en attente. Patiente la reponse du super-admin.', 409);
    }

    db()->prepare("INSERT INTO google_quota_requests (agent_id, motif, granted_extra) VALUES (?, ?, ?)")
        ->execute([(int)$user['id'], $motif ?: null, 10]);
    $reqId = (int) db()->lastInsertId();

    $agentName = trim(((string)($user['prenom'] ?? '')) . ' ' . ((string)($user['nom'] ?? '')));
    if (!$agentName) $agentName = (string)($user['email'] ?? 'agent');
    $body = 'Agent ' . $agentName . ' demande +10 consultations Google Places. Motif : ' . ($motif ?: '(non renseigne)');
    $args = '--project ocre --priority high'
          . ' --title ' . escapeshellarg('Demande extension quota Google Maps')
          . ' --body ' . escapeshellarg($body);
    notifyTelegram($args);

    logAction((int)$user['id'], 'google_quota_request', 'req=' . $reqId);
    jsonOk(['request_id' => $reqId, 'status' => 'pending']);
}

// Toutes les actions ci-dessous requierent super-admin.
if (!$isSuperAdmin) jsonError('super-admin requis', 403);

if ($method === 'GET' && $action === 'list') {
    $statusFilter = (string)($_GET['status'] ?? 'pending');
    if (!in_array($statusFilter, ['pending', 'approved', 'denied', 'all'], true)) $statusFilter = 'pending';
    $sql = "SELECT r.id, r.agent_id, r.requested_at, r.motif, r.status,
                   r.responded_at, r.responder_id, r.response_message, r.granted_extra,
                   u.email AS agent_email, u.prenom AS agent_prenom, u.nom AS agent_nom
              FROM google_quota_requests r
              LEFT JOIN users u ON u.id = r.agent_id";
    $params = [];
    if ($statusFilter !== 'all') { $sql .= " WHERE r.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY r.requested_at DESC LIMIT 200";
    $st = db()->prepare($sql);
    $st->execute($params);
    jsonOk(['requests' => $st->fetchAll() ?: []]);
}

if ($method === 'GET' && $action === 'usage_list') {
    // Conso du jour + cumul mois courant pour chaque agent.
    $monthStart = (new DateTime('now', $casaTz))->format('Y-m-01');
    $sql = "SELECT u.id AS agent_id, u.email, u.prenom, u.nom,
                   COALESCE(today.count, 0) AS today_count,
                   COALESCE(today.daily_limit, ?) AS daily_limit,
                   COALESCE(month.month_count, 0) AS month_count
              FROM users u
              LEFT JOIN google_places_quota today ON today.agent_id = u.id AND today.date = ?
              LEFT JOIN (SELECT agent_id, SUM(count) AS month_count
                           FROM google_places_quota
                          WHERE date >= ?
                          GROUP BY agent_id) month ON month.agent_id = u.id
             WHERE u.active = 1
             ORDER BY today_count DESC, u.email ASC LIMIT 500";
    $st = db()->prepare($sql);
    $st->execute([$globalLimit, $today, $monthStart]);
    jsonOk(['usage' => $st->fetchAll() ?: [], 'global_limit' => $globalLimit, 'today' => $today]);
}

if ($method === 'POST' && ($action === 'approve' || $action === 'deny')) {
    $in = getInput();
    $reqId = (int)($in['request_id'] ?? 0);
    $message = trim((string)($in['response_message'] ?? ''));
    $extra = max(1, (int)($in['granted_extra'] ?? 10));
    if (!$reqId) jsonError('request_id requis', 400);
    if ($message === '') jsonError('response_message requis (argumentation)', 400);

    $st = db()->prepare("SELECT * FROM google_quota_requests WHERE id = ? LIMIT 1");
    $st->execute([$reqId]);
    $req = $st->fetch();
    if (!$req) jsonError('Demande introuvable', 404);
    if ($req['status'] !== 'pending') jsonError('Demande deja traitee : ' . $req['status'], 409);

    $newStatus = $action === 'approve' ? 'approved' : 'denied';
    db()->prepare("UPDATE google_quota_requests
                      SET status = ?, responded_at = NOW(), responder_id = ?, response_message = ?, granted_extra = ?
                    WHERE id = ?")
        ->execute([$newStatus, (int)$user['id'], $message, $extra, $reqId]);

    if ($action === 'approve') {
        // Augmente la limite du jour pour l agent (ligne creee si absente).
        $chk = db()->prepare("SELECT count, daily_limit FROM google_places_quota WHERE agent_id = ? AND date = ? LIMIT 1");
        $chk->execute([(int)$req['agent_id'], $today]);
        $row = $chk->fetch();
        if ($row) {
            db()->prepare("UPDATE google_places_quota SET daily_limit = daily_limit + ? WHERE agent_id = ? AND date = ?")
                ->execute([$extra, (int)$req['agent_id'], $today]);
        } else {
            db()->prepare("INSERT INTO google_places_quota (agent_id, date, count, daily_limit) VALUES (?, ?, 0, ?)")
                ->execute([(int)$req['agent_id'], $today, $globalLimit + $extra]);
        }
    }

    // Notif Telegram super-admin global (pas de canal par agent V1).
    $title = $action === 'approve' ? 'Demande quota Google APPROUVEE' : 'Demande quota Google REFUSEE';
    $body = 'Demande #' . $reqId . ' agent_id=' . (int)$req['agent_id']
          . ($action === 'approve' ? (' +' . $extra . ' consultations.') : '')
          . ' Reponse : ' . $message;
    $args = '--project ocre --priority normal'
          . ' --title ' . escapeshellarg($title)
          . ' --body ' . escapeshellarg($body);
    @exec('/root/bin/notify ' . $args . ' >/dev/null 2>&1 &');

    logAction((int)$user['id'], 'google_quota_' . $action, 'req=' . $reqId . ' agent=' . (int)$req['agent_id']);
    jsonOk(['request_id' => $reqId, 'status' => $newStatus]);
}

if ($method === 'POST' && $action === 'set_global_limit') {
    $in = getInput();
    $limit = (int)($in['limit'] ?? 0);
    if ($limit < 0 || $limit > 10000) jsonError('limit hors plage [0,10000]', 400);
    setSetting('google_places_daily_limit', $limit);
    logAction((int)$user['id'], 'google_quota_set_global_limit', 'limit=' . $limit);
    jsonOk(['google_places_daily_limit' => $limit]);
}

if ($method === 'POST' && $action === 'set_api_key') {
    $in = getInput();
    $key = trim((string)($in['api_key'] ?? ''));
    setSetting('google_places_api_key', $key);
    logAction((int)$user['id'], 'google_quota_set_api_key', 'len=' . strlen($key));
    jsonOk(['ok' => true, 'configured' => $key !== '']);
}

if ($method === 'GET' && $action === 'pending_count') {
    $c = db()->query("SELECT COUNT(*) FROM google_quota_requests WHERE status = 'pending'")->fetchColumn();
    jsonOk(['pending_count' => (int)$c]);
}

jsonError('Action inconnue ou methode non supportee : ' . $method . ' ' . $action, 400);
