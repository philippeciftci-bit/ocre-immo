<?php
/**
 * M/2026/05/13/7 — Rapport mensuel email super-admin
 *
 * Liste les comptes utilisateurs ocre_meta.users avec deletion_requested_at NOT NULL
 * (et anonymized_at NULL = pas encore purgés). Envoie un email HTML recapitulatif au
 * super-admin (OCRE_SUPERADMIN_EMAIL dans /etc/ocre/env) via OVH SMTP (send_mail()
 * de api/lib/email_sender.php).
 *
 * Decision Philippe (M/2026/05/13/5) : pas de purge automatique. Le super-admin
 * decide au cas par cas (Prolonger / Anonymiser / Supprimer definitivement / Conserver).
 *
 * Cron : 1er du mois 9h00 Europe/Paris via ocre-monthly-deletions-report.timer.
 */

declare(strict_types=1);

// ============================================================================
// Bootstrap (charge la config DB d'Ocre + wrapper email OVH SMTP)
// ============================================================================
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/email_sender.php';

$LOG_FILE = '/var/log/ocre/monthly-deletions-report.log';
@mkdir(dirname($LOG_FILE), 0775, true);

function plog(string $msg): void {
    global $LOG_FILE;
    $line = '[' . date('c') . '] ' . $msg . "\n";
    @file_put_contents($LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

// ============================================================================
// Destinataire super-admin (env file)
// ============================================================================
$ENV_FILE = '/etc/ocre/env';
$superAdmin = '';
if (is_readable($ENV_FILE)) {
    foreach (file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (preg_match('/^OCRE_SUPERADMIN_EMAIL\s*=\s*(.+)$/', trim($l), $m)) {
            $superAdmin = trim($m[1], "\"' ");
            break;
        }
    }
}
if ($superAdmin === '') {
    plog('FATAL: OCRE_SUPERADMIN_EMAIL absent de /etc/ocre/env');
    exit(1);
}

// ============================================================================
// Query DB ocre_meta : pending_deletion + tenants associes
// ============================================================================
try {
    $meta = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    plog('FATAL: connexion ocre_meta impossible : ' . $e->getMessage());
    exit(2);
}

$sql = "
    SELECT u.id, u.email, u.prenom, u.nom, u.telephone, u.created_at,
           u.deletion_requested_at,
           DATEDIFF(NOW(), u.deletion_requested_at) AS days_pending,
           GROUP_CONCAT(DISTINCT w.slug ORDER BY w.slug SEPARATOR ', ') AS tenants
    FROM users u
    LEFT JOIN workspace_members wm ON wm.user_id = u.id AND wm.left_at IS NULL
    LEFT JOIN workspaces w ON w.id = wm.workspace_id AND w.archived_at IS NULL
    WHERE u.deletion_requested_at IS NOT NULL
      AND u.anonymized_at IS NULL
    GROUP BY u.id
    ORDER BY u.deletion_requested_at ASC
";
$rows = $meta->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$count = count($rows);
plog("Trouve $count compte(s) en pending_deletion");

// ============================================================================
// Construction email HTML responsive iPhone/iPad
// ============================================================================
$monthFr = strftime_fr_month_year(time());
$subject = 'Oi Agent · Rapport mensuel comptes en attente de suppression — ' . $monthFr;

$css_body = "font-family: -apple-system, BlinkMacSystemFont, 'DM Sans', Arial, sans-serif; color: #3a2e22; line-height: 1.5;";
$css_brand = "color: #8B6F47; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 700; font-size: 26px; margin: 0 0 8px 0;";
$css_sub = "color: #6B5642; font-size: 14px; margin: 0 0 24px 0;";
$css_box = "max-width: 760px; margin: 0 auto; padding: 24px; border: 1px solid #e8d8b9; border-radius: 8px; background: #FAF6F1;";
$css_th = "background: #F4ECDF; padding: 8px 10px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #6B5642; border-bottom: 2px solid #B87333;";
$css_td = "padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #E5DAC6; vertical-align: top;";
$css_td_num = $css_td . " tabular-nums;";
$css_btn = "display: inline-block; padding: 6px 12px; background: #8B6F47; color: #fff; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 600;";
$css_footer = "font-size: 12px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #E5DAC6;";

$html = "<html><body style=\"$css_body background:#fff;\">";
$html .= "<div style=\"$css_box\">";
$html .= "<h1 style=\"$css_brand\">Rapport mensuel — comptes en attente de suppression</h1>";
$html .= "<p style=\"$css_sub\">$monthFr · Genere automatiquement le " . date('d/m/Y H:i') . " UTC · " . ($count === 0 ? 'Aucun compte ce mois-ci' : "$count compte(s) en attente") . "</p>";

if ($count === 0) {
    $html .= '<p style="font-size:14px;padding:24px;background:#E8F2EA;border-radius:8px;color:#2D7A3E;text-align:center;font-weight:600;">'
           . 'Aucun compte utilisateur n\'a demande la suppression ce mois-ci. Tout est calme cote RGPD.'
           . '</p>';
} else {
    $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
    $html .= '<thead><tr>'
           . "<th style=\"$css_th\">Utilisateur</th>"
           . "<th style=\"$css_th\">Email</th>"
           . "<th style=\"$css_th\">Tenant(s)</th>"
           . "<th style=\"$css_th\">Demande</th>"
           . "<th style=\"$css_th\">Jours ecoules</th>"
           . "<th style=\"$css_th\">Action</th>"
           . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $userId = (int)$r['id'];
        $nom = htmlspecialchars(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: '(sans nom)', ENT_QUOTES);
        $email = htmlspecialchars((string)$r['email'], ENT_QUOTES);
        $tenants = htmlspecialchars((string)($r['tenants'] ?? '(aucun)'), ENT_QUOTES);
        $deletionAt = date('d/m/Y', strtotime((string)$r['deletion_requested_at']));
        $daysPending = (int)$r['days_pending'];
        $daysColor = $daysPending >= 30 ? '#B91C1C' : ($daysPending >= 14 ? '#D97706' : '#3a2e22');
        $link = "https://superadmin.ocre.immo/users/$userId";
        $html .= "<tr>"
               . "<td style=\"$css_td\">$nom</td>"
               . "<td style=\"$css_td\">$email</td>"
               . "<td style=\"$css_td\">$tenants</td>"
               . "<td style=\"$css_td_num\">$deletionAt</td>"
               . "<td style=\"$css_td_num color:$daysColor;font-weight:600;\">{$daysPending}j</td>"
               . "<td style=\"$css_td\"><a href=\"$link\" style=\"$css_btn\">Ouvrir</a></td>"
               . "</tr>";
    }
    $html .= '</tbody></table>';
    $html .= '<div style="background:#FFF4E6;border-left:3px solid #B87333;padding:12px 16px;margin:16px 0;font-size:13px;">'
           . '<strong>4 options pour chaque compte :</strong><br>'
           . '<strong>Prolonger</strong> · le garder en pending (decision plus tard) — aucune action requise<br>'
           . '<strong>Anonymiser</strong> · purger PII (email + nom + tel) mais garder l\'ID pour comptabilite — Console super-admin<br>'
           . '<strong>Supprimer definitivement</strong> · suppression complete DB + uploads — Console super-admin<br>'
           . '<strong>Conserver tel quel</strong> · effacer la demande deletion_requested_at (le user reste actif) — UPDATE SQL direct'
           . '</div>';
}

$html .= "<div style=\"$css_footer\">";
$html .= 'Cron : 1er du mois 09:00 Europe/Paris (ocre-monthly-deletions-report.timer).<br>';
$html .= 'Source : <code>ocre_meta.users WHERE deletion_requested_at IS NOT NULL AND anonymized_at IS NULL</code>.<br>';
$html .= 'Script : <code>/opt/ocre-app/cron/monthly_deletions_report.php</code>.<br>';
$html .= 'Pour desactiver : <code>systemctl disable --now ocre-monthly-deletions-report.timer</code>.';
$html .= '</div></div></body></html>';

// ============================================================================
// Envoi via OVH SMTP (send_mail() de api/lib/email_sender.php)
// ============================================================================
$result = send_mail($superAdmin, $subject, $html);

if (!$result['ok']) {
    plog("FAIL email: " . (string)($result['error'] ?? 'unknown'));
    // Audit log dans ocre_meta
    try {
        $meta->prepare("INSERT INTO audit_log (actor_user_id, workspace_id, action, target_type, target_id, payload_json, created_at) VALUES (NULL, NULL, 'monthly_deletions_report.fail', 'system', NULL, ?, NOW())")
             ->execute([json_encode(['error' => $result['error'], 'count' => $count, 'recipient' => $superAdmin], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* best effort */ }
    exit(3);
}

plog("OK email envoye to=$superAdmin message_id=" . (string)($result['message_id'] ?? 'n/a') . " count=$count");

// Audit log success
try {
    $meta->prepare("INSERT INTO audit_log (actor_user_id, workspace_id, action, target_type, target_id, payload_json, created_at) VALUES (NULL, NULL, 'monthly_deletions_report.sent', 'system', NULL, ?, NOW())")
         ->execute([json_encode([
             'count' => $count,
             'recipient' => $superAdmin,
             'message_id' => $result['message_id'],
             'month' => $monthFr,
         ], JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) { /* best effort */ }

exit(0);

// ============================================================================
// Helpers
// ============================================================================
function strftime_fr_month_year(int $ts): string {
    $months = [
        1 => 'Janvier', 2 => 'Fevrier', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Aout',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Decembre',
    ];
    return $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
