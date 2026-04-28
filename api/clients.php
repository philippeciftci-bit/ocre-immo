<?php
require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/_image_hmac.php';
require_once __DIR__ . '/_audit.php';
setCorsHeaders();
// V50 — migration idempotente audit + soft-delete au moindre hit.
auditEnsureSchema();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

// V23 — signe les URLs de photos dans bien.photos[] pour que les <img> browser
// passent la contrainte auth. Chaque URL reçoit &t=<hmac>&e=<epoch+2h>. Si le raw n'a
// pas le préfixe /api/image.php, on le normalise d'abord.
function signPhotoUrl(string $raw): string {
    if (!defined('IMAGE_HMAC_SECRET')) return $raw;
    $path = $raw;
    if (strpos($raw, '/api/image.php?path=') === 0) {
        // Extract encoded path
        $q = parse_url($raw, PHP_URL_QUERY) ?: '';
        parse_str($q, $qs);
        $path = $qs['path'] ?? '';
    } elseif (preg_match('#^https?://#i', $raw)) {
        return $raw; // URL externe (Mubawab, Vaneau…) — pas notre proxy, pas de signature
    }
    $path = ltrim((string) $path, '/');
    if (!$path) return $raw;
    $expires = time() + (defined('IMAGE_HMAC_TTL') ? IMAGE_HMAC_TTL : 7200);
    $t = hash_hmac('sha256', $path . '|' . $expires, IMAGE_HMAC_SECRET);
    return '/api/image.php?path=' . rawurlencode($path) . '&t=' . $t . '&e=' . $expires;
}

function signClientPhotos(array &$d): void {
    if (isset($d['bien']['photos']) && is_array($d['bien']['photos'])) {
        foreach ($d['bien']['photos'] as $i => $raw) {
            if (is_string($raw)) $d['bien']['photos'][$i] = signPhotoUrl($raw);
            elseif (is_array($raw) && isset($raw['url']) && is_string($raw['url'])) {
                $d['bien']['photos'][$i]['url'] = signPhotoUrl($raw['url']);
            }
        }
    }
    if (isset($d['import_image_url']) && is_string($d['import_image_url'])) {
        $d['import_image_url'] = signPhotoUrl($d['import_image_url']);
    }
}

// V17.5 Phase 2c — helpers queue sync Google Sheet.
function ensureSyncSchema() {
    static $done = false;
    if ($done) return;
    try {
        foreach ([
            "ALTER TABLE users ADD COLUMN sync_enabled TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN sync_email VARCHAR(255) NULL",
            "ALTER TABLE users ADD COLUMN sheet_id VARCHAR(100) NULL",
            "ALTER TABLE users ADD COLUMN sheet_created_at DATETIME NULL",
            // V18.17 — staging téléchargements.
            "ALTER TABLE clients ADD COLUMN is_staged TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE clients ADD COLUMN promoted_at DATETIME NULL",
            "ALTER TABLE clients ADD INDEX idx_staged (user_id, is_staged)",
            // V45 — Plan de paiement multi-lignes + Encaissements reçus (mirror du JSON data).
            "ALTER TABLE clients ADD COLUMN payment_plan JSON NULL DEFAULT NULL",
            "ALTER TABLE clients ADD COLUMN received_payments JSON NULL DEFAULT NULL",
        ] as $sql) {
            try { db()->exec($sql); } catch (Exception $e) {}
        }
        db()->exec("CREATE TABLE IF NOT EXISTS ocre_sync_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            dossier_id INT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'upsert',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            error TEXT NULL,
            INDEX idx_user (user_id),
            INDEX idx_pending (processed_at, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) {}
    $done = true;
}
function enqueueSync($user_id, $dossier_id = null, $action = 'upsert') {
    ensureSyncSchema();
    try {
        $chk = db()->prepare("SELECT sync_enabled FROM users WHERE id = ? LIMIT 1");
        $chk->execute([$user_id]);
        $u = $chk->fetch();
        if (!$u || (int)$u['sync_enabled'] !== 1) return;
        $stmt = db()->prepare(
            "INSERT INTO ocre_sync_queue (user_id, dossier_id, action) VALUES (?, ?, ?)"
        );
        $stmt->execute([$user_id, $dossier_id, $action]);
    } catch (Exception $e) { /* silent */ }
}

function tableExists($name) {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    try {
        $st = db()->prepare("SHOW TABLES LIKE ?");
        $st->execute([$name]);
        return $cache[$name] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $cache[$name] = false; }
}

function computeIsDraft($d) {
    $tel = trim((string)($d['tel'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    $hasContact = ($tel !== '' || $email !== '');
    if (!$hasContact) return 1;
    if (($d['profil_type'] ?? '') === 'Société') {
        return (trim((string)($d['societe_nom'] ?? '')) === '') ? 1 : 0;
    }
    $prenom = trim((string)($d['prenom'] ?? ''));
    $nom = trim((string)($d['nom'] ?? ''));
    return ($prenom === '' || $nom === '') ? 1 : 0;
}

switch ($action) {

    case 'list': {
        // V18.17 — filtre is_staged (0 = liste principale, défaut / 1 = téléchargements).
        ensureSyncSchema();
        $staged = isset($_GET['staged']) ? (int)$_GET['staged'] : 0;
        // V50 — soft-delete filter : masquer les deleted_at NOT NULL.
        $stmt = db()->prepare(
            "SELECT id, data, is_draft, archived, projet, is_investisseur, is_staged, promoted_at, updated_at
             FROM clients WHERE user_id = ? AND is_staged = ? AND deleted_at IS NULL ORDER BY updated_at DESC"
        );
        $stmt->execute([$user['id'], $staged ? 1 : 0]);
        $rows = $stmt->fetchAll();
        // V52.4.3 DIAG TEMPORAIRE : log file dédié dans /api/_diag.log lisible via endpoint.
        try {
            $tokenHdr = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? ($_SERVER['HTTP_X_SESSIONTOKEN'] ?? '');
            $isDemoCount = 0;
            foreach ($rows as $rr) {
                $dd = json_decode($rr['data'] ?? '{}', true) ?: [];
                if (!empty($dd['is_demo'])) $isDemoCount++;
            }
            $line = sprintf(
                "[DIAG-%s] list ip=%s ua=%s token=%s... user_id=%s email=%s rows=%d is_demo=%d staged=%d\n",
                date('c'),
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 60),
                substr($tokenHdr, 0, 12),
                $user['id'] ?? 'NONE',
                $user['email'] ?? 'NONE',
                count($rows),
                $isDemoCount,
                $staged
            );
            @file_put_contents(__DIR__ . '/_diag.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {}
        // V18.17 — count staged séparé pour badge header 📥 N.
        $stagedCount = 0;
        try {
            $c = db()->prepare("SELECT COUNT(*) n FROM clients WHERE user_id = ? AND is_staged = 1 AND deleted_at IS NULL");
            $c->execute([$user['id']]);
            $stagedCount = (int)($c->fetch()['n'] ?? 0);
        } catch (Exception $e) {}
        // V18.2 — précharge next_event / next_todo / last_interaction par client (3 batch queries).
        $client_ids = array_map(fn($r) => (int)$r['id'], $rows);
        $next_events = []; $next_todos = []; $last_inter = [];
        if ($client_ids && tableExists('suivi_events')) {
            $stmt = db()->prepare(
                "SELECT e.* FROM suivi_events e
                 JOIN (
                   SELECT client_id, MIN(when_at) AS w
                   FROM suivi_events
                   WHERE user_id = ? AND status = 'planned' AND when_at > NOW()
                   GROUP BY client_id
                 ) m ON m.client_id = e.client_id AND m.w = e.when_at
                 WHERE e.user_id = ? AND e.status = 'planned'"
            );
            $stmt->execute([$user['id'], $user['id']]);
            foreach ($stmt->fetchAll() as $e) $next_events[(int)$e['client_id']] = $e;
        }
        if ($client_ids && tableExists('suivi_todos')) {
            $stmt = db()->prepare(
                "SELECT t.* FROM suivi_todos t
                 JOIN (
                   SELECT client_id, MIN(due_at) AS d
                   FROM suivi_todos
                   WHERE user_id = ? AND done = 0 AND due_at IS NOT NULL AND client_id IS NOT NULL
                   GROUP BY client_id
                 ) m ON m.client_id = t.client_id AND m.d = t.due_at
                 WHERE t.user_id = ? AND t.done = 0"
            );
            $stmt->execute([$user['id'], $user['id']]);
            foreach ($stmt->fetchAll() as $t) $next_todos[(int)$t['client_id']] = $t;
        }
        if ($client_ids && tableExists('suivi_journal')) {
            $stmt = db()->prepare(
                "SELECT j.* FROM suivi_journal j
                 JOIN (
                   SELECT client_id, MAX(ts) AS m
                   FROM suivi_journal
                   WHERE user_id = ?
                   GROUP BY client_id
                 ) x ON x.client_id = j.client_id AND x.m = j.ts
                 WHERE j.user_id = ?"
            );
            $stmt->execute([$user['id'], $user['id']]);
            foreach ($stmt->fetchAll() as $j) $last_inter[(int)$j['client_id']] = $j;
        }
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode($r['data'] ?? '{}', true) ?: [];
            $d['id'] = (int)$r['id'];
            $d['archived'] = (bool)(int)$r['archived'];
            $d['is_draft'] = (bool)(int)$r['is_draft'];
            $d['is_staged'] = (bool)(int)($r['is_staged'] ?? 0);
            $d['promoted_at'] = $r['promoted_at'] ?? null;
            $d['projet'] = $r['projet'] ?? ($d['projet'] ?? 'Acheteur');
            $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
            $d['updated_at'] = $r['updated_at'];
            $cid = (int)$r['id'];
            $d['suivi'] = [
                'next_event' => $next_events[$cid] ?? null,
                'next_todo' => $next_todos[$cid] ?? null,
                'last_interaction' => $last_inter[$cid] ?? null,
            ];
            signClientPhotos($d);
            $out[] = $d;
        }
        jsonOk(['clients' => $out, 'meta' => ['staged_count' => $stagedCount]]);
    }

    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $user['id']]);
        $r = $stmt->fetch();
        if (!$r) jsonError('Introuvable', 404);
        $d = json_decode($r['data'] ?? '{}', true) ?: [];
        $d['id'] = (int)$r['id'];
        $d['archived'] = (bool)(int)$r['archived'];
        $d['is_draft'] = (bool)(int)$r['is_draft'];
        $d['projet'] = $r['projet'] ?? ($d['projet'] ?? 'Acheteur');
        $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
        signClientPhotos($d);
        jsonOk(['client' => $d]);
    }

    case 'save': {
        ensureSyncSchema();
        $c = $input['client'] ?? [];
        if (!is_array($c)) jsonError('client invalide');
        $id = isset($c['id']) ? (int)$c['id'] : 0;
        $projet = (string)($c['projet'] ?? 'Acheteur');
        $is_investisseur = !empty($c['is_investisseur']) ? 1 : 0;
        $archived = !empty($c['archived']) ? 1 : 0;
        $is_draft = computeIsDraft($c);
        // V18.17 — is_staged respecté à l'INSERT (import URL/image crée staged).
        // En UPDATE : on conserve l'existant (promote est géré par action=promote dédié).
        $is_staged_new = !empty($c['is_staged']) ? 1 : 0;
        $prenom = substr(trim((string)($c['prenom'] ?? '')), 0, 100);
        $nom = substr(trim((string)($c['nom'] ?? '')), 0, 100);
        $societe_nom = substr(trim((string)($c['societe_nom'] ?? '')), 0, 150);
        $tel = substr(trim((string)($c['tel'] ?? '')), 0, 30);
        $email = substr(trim((string)($c['email'] ?? '')), 0, 150);
        // V45 — payment_plan + received_payments validés et mirrorés en colonnes JSON.
        $payment_plan = null;
        if (isset($c['payment_plan']) && is_array($c['payment_plan'])) {
            $valid = [];
            foreach ($c['payment_plan'] as $line) {
                if (!is_array($line)) continue;
                $amt = isset($line['amount']) ? (float)$line['amount'] : 0;
                $cur = isset($line['currency']) ? (string)$line['currency'] : 'MAD';
                $met = isset($line['method']) ? (string)$line['method'] : 'wire';
                if ($amt <= 0) continue;
                if (!in_array($cur, ['MAD','EUR','USD'], true)) $cur = 'MAD';
                if (!in_array($met, ['cash','wire'], true)) $met = 'wire';
                $valid[] = [
                    'id' => isset($line['id']) ? (string)$line['id'] : null,
                    'amount' => $amt, 'currency' => $cur, 'method' => $met,
                ];
            }
            $payment_plan = $valid;
            $c['payment_plan'] = $valid;
        }
        $received_payments = null;
        if (isset($c['received_payments']) && is_array($c['received_payments'])) {
            $valid = [];
            foreach ($c['received_payments'] as $line) {
                if (!is_array($line)) continue;
                $amt = isset($line['amount']) ? (float)$line['amount'] : 0;
                $cur = isset($line['currency']) ? (string)$line['currency'] : 'MAD';
                $met = isset($line['method']) ? (string)$line['method'] : 'wire';
                $dt = isset($line['date']) ? (string)$line['date'] : '';
                if ($amt <= 0) continue;
                if (!in_array($cur, ['MAD','EUR','USD'], true)) $cur = 'MAD';
                if (!in_array($met, ['cash','wire'], true)) $met = 'wire';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = null;
                $valid[] = [
                    'id' => isset($line['id']) ? (string)$line['id'] : null,
                    'date' => $dt, 'amount' => $amt, 'currency' => $cur, 'method' => $met,
                ];
            }
            $received_payments = $valid;
            $c['received_payments'] = $valid;
        }
        $data = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payment_plan_json = $payment_plan === null ? null : json_encode($payment_plan, JSON_UNESCAPED_UNICODE);
        $received_payments_json = $received_payments === null ? null : json_encode($received_payments, JSON_UNESCAPED_UNICODE);

        $wasStaged = false;
        $audit_before = null;
        if ($id > 0) {
            // V50 — capture before-state pour audit_log UPDATE.
            $beforeStmt = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ?");
            $beforeStmt->execute([$id, $user['id']]);
            $audit_before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$audit_before) jsonError('Accès refusé', 403);
            $wasStaged = (int)($audit_before['is_staged'] ?? 0) === 1;
            $stmt = db()->prepare(
                "UPDATE clients SET data = ?, projet = ?, is_investisseur = ?, archived = ?,
                   is_draft = ?, prenom = ?, nom = ?, societe_nom = ?, tel = ?, email = ?,
                   payment_plan = ?, received_payments = ?,
                   updated_at = NOW()
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$data, $projet, $is_investisseur, $archived, $is_draft,
                            $prenom, $nom, $societe_nom, $tel, $email,
                            $payment_plan_json, $received_payments_json,
                            $id, $user['id']]);
        } else {
            $stmt = db()->prepare(
                "INSERT INTO clients (user_id, data, projet, is_investisseur, archived,
                                      is_draft, is_staged, prenom, nom, societe_nom, tel, email,
                                      payment_plan, received_payments,
                                      created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([$user['id'], $data, $projet, $is_investisseur, $archived, $is_draft, $is_staged_new,
                            $prenom, $nom, $societe_nom, $tel, $email,
                            $payment_plan_json, $received_payments_json]);
            $id = (int)db()->lastInsertId();
            $wasStaged = (bool)$is_staged_new;
        }
        $c['id'] = $id;
        $c['is_draft'] = (bool)$is_draft;
        $c['archived'] = (bool)$archived;
        $c['is_staged'] = $wasStaged;
        // V50 — audit INSERT/UPDATE avec before/after (JSON data + champs plats).
        $audit_after = [
            'id' => $id, 'projet' => $projet, 'is_draft' => $is_draft, 'archived' => $archived,
            'is_staged' => $wasStaged ? 1 : 0, 'prenom' => $prenom, 'nom' => $nom,
            'societe_nom' => $societe_nom, 'tel' => $tel, 'email' => $email,
            'payment_plan' => $payment_plan, 'received_payments' => $received_payments,
        ];
        audit_log((int)$user['id'], 'clients', $id, $audit_before ? 'UPDATE' : 'INSERT', $audit_before, $audit_after);
        // V17.5 Phase 2c : enqueue sync Google Sheet si user sync_enabled. V18.17 : skip si staged.
        if (!$wasStaged) enqueueSync((int)$user['id'], $id, 'upsert');
        jsonOk(['client' => $c]);
    }

    case 'promote': {
        // V18.17 — passe un dossier de staging à la liste principale.
        ensureSyncSchema();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare(
            "UPDATE clients SET is_staged = 0, promoted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND user_id = ? AND is_staged = 1"
        );
        $stmt->execute([$id, $user['id']]);
        if ($stmt->rowCount() === 0) jsonError('dossier introuvable ou déjà promu', 404);
        logAction((int)$user['id'], 'client_promote', "id=$id");
        enqueueSync((int)$user['id'], $id, 'upsert');
        jsonOk(['id' => $id, 'promoted' => true]);
    }

    case 'delete': {
        // V50 — soft delete (zéro destruction directe). Rétention 90j via cron, puis purge
        // physique avec dump JSON /root/backups. Restaurable via restore.php.
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $ok = soft_delete('clients', $id, (int)$user['id'], (int)$user['id']);
        if (!$ok) jsonError('Introuvable ou déjà supprimé', 404);
        logAction((int)$user['id'], 'client_soft_delete', "id=$id");
        enqueueSync((int)$user['id'], $id, 'delete');
        jsonOk(['deleted' => $id, 'soft' => true]);
    }

    case 'restore': {
        // V50 — restauration soft-delete.
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) jsonError('id requis');
        $ok = soft_restore('clients', $id, (int)$user['id'], (int)$user['id']);
        if (!$ok) jsonError('Introuvable ou pas supprimé', 404);
        logAction((int)$user['id'], 'client_restore', "id=$id");
        enqueueSync((int)$user['id'], $id, 'upsert');
        jsonOk(['restored' => $id]);
    }

    case 'sync_prefs_get': {
        ensureSyncSchema();
        $stmt = db()->prepare("SELECT sync_enabled, sync_email, sheet_id, sheet_created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $r = $stmt->fetch() ?: [];
        $sheet_url = !empty($r['sheet_id']) ? ('https://docs.google.com/spreadsheets/d/' . $r['sheet_id']) : null;
        jsonOk([
            'sync_enabled' => (bool)(int)($r['sync_enabled'] ?? 0),
            'sync_email' => $r['sync_email'] ?? '',
            'sheet_id' => $r['sheet_id'] ?? '',
            'sheet_url' => $sheet_url,
            'sheet_created_at' => $r['sheet_created_at'] ?? null,
            // V17.5 Phase 2c : SA email que l'user doit inviter en Editor sur son Sheet.
            'service_account_email' => 'ocre-vps-sync@my-project-test-400021.iam.gserviceaccount.com',
        ]);
    }

    case 'update_sync_prefs': {
        ensureSyncSchema();
        $enabled = !empty($input['sync_enabled']) ? 1 : 0;
        $email = substr(trim((string)($input['sync_email'] ?? '')), 0, 255);
        // V17.5 Phase 2c : Gmail perso bloque création Sheet par SA (quota=0).
        // L'user doit créer manuellement un Sheet et le partager avec le SA.
        // Il colle l'URL ici → on extrait l'ID.
        $sheet_url = trim((string)($input['sheet_url'] ?? ''));
        $sheet_id = null;
        if ($sheet_url !== '') {
            if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $sheet_url, $m)) {
                $sheet_id = $m[1];
            } elseif (preg_match('#^[a-zA-Z0-9_-]{20,}$#', $sheet_url)) {
                $sheet_id = $sheet_url; // ID seul collé
            } else {
                jsonError('URL Google Sheet invalide (attendu: https://docs.google.com/spreadsheets/d/…)');
            }
        }
        if ($enabled && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email Google invalide');
        if ($sheet_id) {
            $stmt = db()->prepare("UPDATE users SET sync_enabled = ?, sync_email = ?, sheet_id = ?, sheet_created_at = COALESCE(sheet_created_at, NOW()) WHERE id = ?");
            $stmt->execute([$enabled, $email ?: null, $sheet_id, $user['id']]);
        } else {
            $stmt = db()->prepare("UPDATE users SET sync_enabled = ?, sync_email = ? WHERE id = ?");
            $stmt->execute([$enabled, $email ?: null, $user['id']]);
        }
        if ($enabled) enqueueSync((int)$user['id'], null, 'full_sync');
        jsonOk(['sync_enabled' => (bool)$enabled, 'sync_email' => $email, 'sheet_id' => $sheet_id]);
    }

    case 'sync_now': {
        // Force a full-sync trigger, même si déjà activé.
        ensureSyncSchema();
        $chk = db()->prepare("SELECT sync_enabled, sync_email FROM users WHERE id = ? LIMIT 1");
        $chk->execute([$user['id']]);
        $u = $chk->fetch();
        if (!$u || !(int)$u['sync_enabled']) jsonError('Sync non activée');
        if (!$u['sync_email']) jsonError('Email Google manquant');
        enqueueSync((int)$user['id'], null, 'full_sync');
        jsonOk(['queued' => true]);
    }

    case 'archive': {
        $id = (int)($input['id'] ?? 0);
        $archived = !empty($input['archived']) ? 1 : 0;
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("UPDATE clients SET archived = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$archived, $id, $user['id']]);
        jsonOk(['id' => $id, 'archived' => (bool)$archived]);
    }

    case 'unarchive': {
        // V43 — alias action=unarchive pour désarchivage explicite. Equivaut à
        // archive avec archived=0.
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("UPDATE clients SET archived = 0 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        jsonOk(['id' => $id, 'archived' => false]);
    }

    // V17.1 fix-ux-3 — suggestions basées sur les saisies antérieures de l'utilisateur.
    case 'suggest_city': {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));
        if ($q === '') {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) AS ville
                 FROM clients WHERE user_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) IS NOT NULL
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) AS ville
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) LIKE ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $q . '%');
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $items = array_values(array_filter(array_map(fn($r) => $r['ville'], $stmt->fetchAll())));
        jsonOk(['items' => $items]);
    }

    case 'suggest_address': {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));
        if ($q === '') {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) AS adresse
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) IS NOT NULL
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) AS adresse
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) LIKE ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, '%' . $q . '%');
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $items = array_values(array_filter(array_map(fn($r) => $r['adresse'], $stmt->fetchAll())));
        jsonOk(['items' => $items]);
    }

    default:
        jsonError('Action inconnue', 404);
}
