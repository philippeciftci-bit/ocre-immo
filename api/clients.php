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
    // M/2026/05/01/30 — M108 : promotion brouillon -> dossier UNIQUEMENT par tap ✓ Valider
    // explicit du frontend (envoi is_draft: 0 dans le payload). Default = 1 (brouillon).
    // Plus de promotion auto basee sur prenom+nom+tel : le backend respecte la volonte user
    // explicite. La logique ancienne (basee sur contenu suffisant) est devenue obsolete avec
    // le pattern draft-on-blur M103+M104 + bouton Valider explicite.
    if (isset($d['is_draft']) && $d['is_draft'] !== null && $d['is_draft'] !== '') {
        return ((int)$d['is_draft']) === 0 ? 0 : 1;
    }
    return 1;
}

switch ($action) {

    case 'list': {
        // V18.17 — filtre is_staged (0 = liste principale, défaut / 1 = téléchargements).
        ensureSyncSchema();
        $staged = isset($_GET['staged']) ? (int)$_GET['staged'] : 0;
        // V50 — soft-delete filter : masquer les deleted_at NOT NULL.
        $stmt = db()->prepare(
            "SELECT id, data, is_draft, archived, projet, is_investisseur, is_staged, promoted_at, updated_at,
                    prenom, nom, societe_nom, tel, email, vertical, seed_id
             FROM clients WHERE user_id = ? AND is_staged = ? AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC"
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
            // M/2026/05/09/73 — projet NULL = pas de profil choisi (frontend affiche 'Choisir un profil').
            $d['projet'] = $r['projet'] ?? ($d['projet'] ?? null);
            $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
            $d['updated_at'] = $r['updated_at'];
            // M/2026/04/28/24 — fusionne colonnes top-level (frontend lit
            // c.prenom, c.nom, c.societe_nom directement, sinon « Sans nom »).
            $d['prenom'] = $r['prenom'];
            $d['nom'] = $r['nom'];
            $d['societe_nom'] = $r['societe_nom'];
            $d['tel'] = $r['tel'];
            $d['email'] = $r['email'];
            $d['vertical'] = $r['vertical'];
            $d['seed_id'] = $r['seed_id'];
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
        // M/2026/05/09/73 — projet NULL si pas de profil choisi.
        $d['projet'] = $r['projet'] ?? ($d['projet'] ?? null);
        $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
        // M/2026/04/28/24 — fusionne colonnes top-level (sinon perte des
        // champs identité dans le formulaire d'édition).
        $d['prenom'] = $r['prenom'];
        $d['nom'] = $r['nom'];
        $d['societe_nom'] = $r['societe_nom'];
        $d['tel'] = $r['tel'];
        $d['email'] = $r['email'];
        $d['vertical'] = $r['vertical'];
        $d['seed_id'] = $r['seed_id'] ?? null;
        signClientPhotos($d);
        jsonOk(['client' => $d]);
    }

    case 'save': {
        ensureSyncSchema();
        $c = $input['client'] ?? [];
        if (!is_array($c)) jsonError('client invalide');
        $id = isset($c['id']) ? (int)$c['id'] : 0;
        // M/2026/05/04/16 — anti-fantome : si pas d'id ET aucune donnee utile, retourner ok
        // sans creer de row. Le frontend doit n'appeler save qu'au premier onChange reel.
        if ($id === 0) {
            $hasContent = false;
            foreach (['prenom','nom','societe_nom','tel','email','profil_type'] as $k) {
                if (!empty($c[$k]) && trim((string)$c[$k]) !== '') { $hasContent = true; break; }
            }
            if (!$hasContent && isset($c['bien']) && is_array($c['bien'])) {
                foreach (['ville','quartier','type','pays'] as $k) {
                    if (!empty($c['bien'][$k])) { $hasContent = true; break; }
                }
            }
            if (!$hasContent) {
                jsonOk(['client' => ['id' => null, 'is_draft' => true, 'archived' => false, 'is_staged' => false, 'skipped_empty' => true]]);
            }
        }
        // M/2026/05/09/73 — projet NULL si pas explicitement choisi (suppression default Acheteur silencieux).
        $projetIn = $c['projet'] ?? null;
        $projet = ($projetIn !== null && $projetIn !== '') ? (string)$projetIn : null;
        $is_investisseur = !empty($c['is_investisseur']) ? 1 : 0;
        $archived = !empty($c['archived']) ? 1 : 0;
        $is_draft = computeIsDraft($c);
        // M/2026/05/06/71 — statut dossier (brouillon / enregistre / archive) explicit.
        // Si fourni en payload : utilise. Sinon derive de archived/is_draft.
        $allowedStatuts = ['brouillon','enregistre','archive'];
        $statutInput = isset($c['statut']) ? (string)$c['statut'] : null;
        $statut = in_array($statutInput, $allowedStatuts, true)
            ? $statutInput
            : ($archived ? 'archive' : ($is_draft ? 'brouillon' : 'enregistre'));
        // M/2026/05/02/1 — M109 : nouveaux champs profil avancé + multi-pays.
        // Validation matrice de compatibilité serveur (defense en profondeur, le frontend
        // applique deja la matrice cote UI mais on revalide pour eviter toute injection).
        $is_promoteur          = !empty($c['is_promoteur'])          ? 1 : 0;
        $is_marchand_de_biens  = !empty($c['is_marchand_de_biens'])  ? 1 : 0;
        $profil_lc = $projet !== null ? strtolower($projet) : '';
        // Locataire : aucun toggle avance autorise.
        if ($profil_lc === 'locataire') { $is_investisseur = 0; $is_promoteur = 0; $is_marchand_de_biens = 0; }
        // Acheteur : pas de Promoteur.
        if ($profil_lc === 'acheteur') { $is_promoteur = 0; }
        // Vendeur : pas d Investisseur.
        if ($profil_lc === 'vendeur')  { $is_investisseur = 0; }
        // Bailleur : pas de Promoteur ni Marchand.
        if ($profil_lc === 'bailleur') { $is_promoteur = 0; $is_marchand_de_biens = 0; }
        // Mutex Promoteur <-> Marchand de biens (le frontend devrait empecher mais double-guard).
        if ($is_promoteur && $is_marchand_de_biens) {
            // Garder le plus recent en priorite : si payload contient explicitement les deux,
            // privilegier marchand (plus generique). Logique alternative possible si Philippe precise.
            $is_promoteur = 0;
        }
        // Sanitize multi-pays (ISO2 alpha uppercase, max 2 chars). Null si vide.
        $sanIso2 = function ($v) {
            $v = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$v));
            return (strlen($v) === 2) ? $v : null;
        };
        $phone_country = $sanIso2($c['phone_country'] ?? null);
        $phone_e164    = isset($c['phone_e164']) && $c['phone_e164'] !== '' ? substr(preg_replace('/[^0-9+]/', '', (string)$c['phone_e164']), 0, 20) : null;
        $id_country    = $sanIso2($c['id_country'] ?? null);
        $id_type       = isset($c['id_type'])   && $c['id_type']   !== '' ? substr(trim((string)$c['id_type']),   0, 20)  : null;
        $id_number     = isset($c['id_number']) && $c['id_number'] !== '' ? substr(trim((string)$c['id_number']), 0, 50)  : null;
        $bien_country  = $sanIso2($c['bien_country'] ?? null);
        // M/2026/05/02/7 — M120 livrable D : fallback bien_country = bien.pays (geoIP detect_country
        // pre-remplit bien.pays au mount FormView via M107). Garantit que bien_country DB col
        // reflete toujours le pays du bien sans necessiter une mise a jour manuelle frontend.
        if (!$bien_country && isset($c['bien']) && is_array($c['bien']) && !empty($c['bien']['pays'])) {
            $bien_country = $sanIso2($c['bien']['pays']);
        }
        // V18.17 — is_staged respecté à l'INSERT (import URL/image crée staged).
        // En UPDATE : on conserve l'existant (promote est géré par action=promote dédié).
        $is_staged_new = !empty($c['is_staged']) ? 1 : 0;
        $prenom = substr(trim((string)($c['prenom'] ?? '')), 0, 100);
        $nom = substr(trim((string)($c['nom'] ?? '')), 0, 100);
        $societe_nom = substr(trim((string)($c['societe_nom'] ?? '')), 0, 150);
        $tel = substr(trim((string)($c['tel'] ?? '')), 0, 30);
        $email = substr(trim((string)($c['email'] ?? '')), 0, 150);
        // M/2026/05/05/58 — destinataire personnalise PDF (optionnel par bien). Non affiche en M/58, persiste pour M/59.
        $destinataire_nom = isset($c['destinataire_nom']) ? substr(trim((string)$c['destinataire_nom']), 0, 200) : null;
        $destinataire_email = isset($c['destinataire_email']) ? substr(trim((string)$c['destinataire_email']), 0, 200) : null;
        if ($destinataire_nom === '') $destinataire_nom = null;
        if ($destinataire_email === '') $destinataire_email = null;
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
            // M/2026/05/06/71 — verrou profil post-publication : si statut courant DB = 'enregistre',
            // toute mutation du champ projet est refusee (le profil est fige apres transition brouillon -> enregistre).
            $beforeStatut = (string)($audit_before['statut'] ?? '');
            $beforeProjet = (string)($audit_before['projet'] ?? '');
            if ($beforeStatut === 'enregistre' && $projet !== $beforeProjet && $beforeProjet !== '') {
                jsonError('Profil verrouille apres publication. Pour changer de profil, creer un nouveau dossier (autre casquette).', 409);
            }
            $stmt = db()->prepare(
                "UPDATE clients SET data = ?, projet = ?, is_investisseur = ?, archived = ?,
                   is_draft = ?, prenom = ?, nom = ?, societe_nom = ?, tel = ?, email = ?,
                   payment_plan = ?, received_payments = ?,
                   is_promoteur = ?, is_marchand_de_biens = ?,
                   phone_country = ?, phone_e164 = ?,
                   id_country = ?, id_type = ?, id_number = ?,
                   bien_country = ?,
                   destinataire_nom = ?, destinataire_email = ?,
                   statut = ?,
                   updated_at = NOW()
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$data, $projet, $is_investisseur, $archived, $is_draft,
                            $prenom, $nom, $societe_nom, $tel, $email,
                            $payment_plan_json, $received_payments_json,
                            $is_promoteur, $is_marchand_de_biens,
                            $phone_country, $phone_e164,
                            $id_country, $id_type, $id_number,
                            $bien_country,
                            $destinataire_nom, $destinataire_email,
                            $statut,
                            $id, $user['id']]);
        } else {
            $stmt = db()->prepare(
                "INSERT INTO clients (user_id, data, projet, is_investisseur, archived,
                                      is_draft, is_staged, prenom, nom, societe_nom, tel, email,
                                      payment_plan, received_payments,
                                      is_promoteur, is_marchand_de_biens,
                                      phone_country, phone_e164,
                                      id_country, id_type, id_number,
                                      bien_country,
                                      destinataire_nom, destinataire_email,
                                      statut,
                                      created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            // M/2026/05/06/71 — toute nouvelle fiche INSERT demarre en statut 'brouillon'.
            $statutInsert = $is_staged_new ? 'brouillon' : ($archived ? 'archive' : 'brouillon');
            $stmt->execute([$user['id'], $data, $projet, $is_investisseur, $archived, $is_draft, $is_staged_new,
                            $prenom, $nom, $societe_nom, $tel, $email,
                            $payment_plan_json, $received_payments_json,
                            $is_promoteur, $is_marchand_de_biens,
                            $phone_country, $phone_e164,
                            $id_country, $id_type, $id_number,
                            $bien_country,
                            $destinataire_nom, $destinataire_email,
                            $statutInsert]);
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
        // M/2026/04/29/7 — quota check pour création (audit_before === null) sur dossiers actifs.
        if (!$audit_before && !$is_draft) {
            require_once __DIR__ . '/lib/quota_alerts.php';
            $q = quota_check((int) $user['id'], 'dossiers', quota_count_user_dossiers((int) $user['id']));
            if (!$q['ok']) {
                // Bloque mais on a déjà inséré ; on peut soft-delete pour respecter limite.
                db()->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
                jsonError(quota_alert_message('dossiers', $q), 403);
            }
        }
        // M/2026/04/28/59 — enqueue scan matching auto (worker depile toutes 30s).
        if (!$wasStaged) {
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS match_queue (
                    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    processed_at DATETIME NULL,
                    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                    error_message TEXT NULL,
                    INDEX idx_status_requested (status, requested_at),
                    INDEX idx_client (client_id)
                ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                // Dedup : skip si déjà pending sur ce client_id depuis < 60s.
                $dup = db()->prepare("SELECT id FROM match_queue WHERE client_id = ? AND status = 'pending' AND requested_at > NOW() - INTERVAL 60 SECOND LIMIT 1");
                $dup->execute([$id]);
                if (!$dup->fetch()) {
                    db()->prepare("INSERT INTO match_queue (client_id, status) VALUES (?, 'pending')")->execute([$id]);
                }
            } catch (Throwable $e) {}
        }
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
        // M/2026/05/09/78 — garde-fou : refuser DELETE si dossier non archivé (anti-perte donnees).
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("SELECT archived FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Introuvable', 404);
        if ((int)$row['archived'] !== 1) {
            jsonError('Cannot delete a non-archived contact. Archive it first.', 400);
        }
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
