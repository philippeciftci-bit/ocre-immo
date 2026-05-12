<?php
// M/2026/05/13/1 — Cron purge auto comptes pending_deletion apres delai de grace 30j (RGPD Article 17).
// Marque dans ocre_meta.users : deletion_requested_at (NULL = pas de demande, valeur = demande active).
// Apres NOW() - deletion_requested_at > 30 jours -> anonymisation + suppression physique uploads.
//
// SECURITE :
// - DRY-RUN par defaut. Pour passer en mode reel : --execute en argv.
// - Limite hard 50 comptes/run (eviter purge massive accidentelle).
// - Log dans /var/log/ocre-purge.log + Telegram notif via /root/bin/notify si dispo.
//
// Usage :
//   php /opt/ocre-app/cron/process_pending_deletions.php          (DRY-RUN, affiche ce qui serait fait)
//   php /opt/ocre-app/cron/process_pending_deletions.php --execute (mode reel)

require_once __DIR__ . '/../api/db.php';

$DRY_RUN = !in_array('--execute', $argv ?? [], true);
$MAX_PURGE_PER_RUN = 50;
$GRACE_DAYS = 30;
$LOG_FILE = '/var/log/ocre-purge.log';

function plog($msg, $logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

plog('=== process_pending_deletions START (dry_run=' . ($DRY_RUN ? 'YES' : 'NO') . ') ===', $LOG_FILE);

try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    plog('FATAL : connexion ocre_meta impossible : ' . $e->getMessage(), $LOG_FILE);
    exit(1);
}

// Selection : comptes avec deletion_requested_at non null + delai grace ecoule + non encore anonymises.
$st = $meta->prepare("SELECT id, email, prenom, nom, deletion_requested_at, anonymized_at
    FROM users
    WHERE deletion_requested_at IS NOT NULL
      AND anonymized_at IS NULL
      AND deletion_requested_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY deletion_requested_at ASC
    LIMIT ?");
$st->bindValue(1, $GRACE_DAYS, PDO::PARAM_INT);
$st->bindValue(2, $MAX_PURGE_PER_RUN, PDO::PARAM_INT);
$st->execute();
$candidates = $st->fetchAll() ?: [];

plog('Candidats trouves : ' . count($candidates), $LOG_FILE);

if (empty($candidates)) {
    plog('Aucun compte a purger.', $LOG_FILE);
    plog('=== END ===', $LOG_FILE);
    exit(0);
}

$purged = 0;
$failed = 0;

foreach ($candidates as $c) {
    $uid = (int) $c['id'];
    $email = $c['email'];
    plog("Compte uid=$uid email=$email demande_at=" . $c['deletion_requested_at'], $LOG_FILE);

    if ($DRY_RUN) {
        plog("  [DRY-RUN] anonymiserait email + DROP donnees /uploads/agents/$uid + UPDATE anonymized_at", $LOG_FILE);
        continue;
    }

    try {
        $meta->beginTransaction();

        // 1. Anonymisation user (preservation pour audit comptable 10 ans).
        $anonEmail = 'deleted-' . $uid . '-' . bin2hex(random_bytes(4)) . '@anonymized.ocre.immo';
        $up = $meta->prepare("UPDATE users SET
            email = ?, prenom = NULL, nom = NULL, display_name = NULL,
            telephone = NULL, whatsapp = NULL, photo_url = NULL,
            tagline = NULL, bio = NULL, telephone_pro = NULL, email_pro = NULL,
            whatsapp_pro = NULL, ville = NULL, cp = NULL, societe = NULL,
            anonymized_at = NOW(), is_suspended = 1
            WHERE id = ?");
        $up->execute([$anonEmail, $uid]);

        // 2. Revoke toutes sessions.
        $meta->prepare("UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$uid]);

        // 3. Suppression physique fichiers uploads/agents/<uid>/ (photo profil).
        $agentDir = '/opt/ocre-app/uploads/agents/' . $uid;
        if (is_dir($agentDir)) {
            foreach (glob($agentDir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
            @rmdir($agentDir);
            plog("  Supprime $agentDir", $LOG_FILE);
        }

        // 4. Dossiers clients : marque comme orphelins (user_id -> NULL?) ou suppression (decision metier).
        //    V2 conservateur : on garde les dossiers (anonymises au niveau utilisateur, factures preservees comptable).
        //    V3 future : decision sur soft-delete des dossiers.

        $meta->commit();
        plog("  OK anonymise uid=$uid -> $anonEmail", $LOG_FILE);
        $purged++;
    } catch (Throwable $e) {
        if ($meta->inTransaction()) $meta->rollBack();
        plog("  ECHEC uid=$uid : " . $e->getMessage(), $LOG_FILE);
        $failed++;
    }
}

plog("Resume : $purged purges OK, $failed echecs sur " . count($candidates) . " candidats.", $LOG_FILE);

// Notif Telegram si compte purge.
if (!$DRY_RUN && $purged > 0) {
    $notify = '/root/bin/notify';
    if (is_executable($notify)) {
        $cmd = sprintf('%s --project ocre --priority info --title %s --body %s 2>/dev/null',
            escapeshellarg($notify),
            escapeshellarg('Cron purge RGPD : ' . $purged . ' compte(s) anonymise(s)'),
            escapeshellarg('Anonymisation automatique apres delai 30j. Voir ' . $LOG_FILE)
        );
        @shell_exec($cmd);
    }
}

plog('=== END ===', $LOG_FILE);
exit($failed > 0 ? 2 : 0);
