<?php
// V18.42 — Emailing transactionnel via Resend API.
// sendEmail() envoie + logge dans email_logs. Templates brandés OCRE immo.

require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/_smtp_config.php';

function emailEnabled(): bool {
    return defined('SMTP_PROVIDER') && defined('SMTP_API_KEY') && SMTP_API_KEY;
}

function ensureEmailSchema(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS email_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            to_address VARCHAR(255) NOT NULL,
            template VARCHAR(64) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
            provider_id VARCHAR(128) DEFAULT NULL,
            error TEXT DEFAULT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            meta TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_to (to_address),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* silent */ }
    try {
        db()->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Exception $e) { /* colonne existe déjà */ }
    $done = true;
}

function logEmail(string $to, string $template, string $subject, string $status, ?string $providerId, ?string $error, ?int $userId, array $meta = []): void {
    try {
        ensureEmailSchema();
        $st = db()->prepare("INSERT INTO email_logs (to_address, template, subject, status, provider_id, error, user_id, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $to, $template, $subject, $status, $providerId, $error, $userId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Exception $e) { /* silent */ }
}

function userAllowsEmails(string $email): bool {
    try {
        $st = db()->prepare("SELECT email_notifications FROM users WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch();
        if (!$row) return true; // user externe : on envoie
        return (int) $row['email_notifications'] === 1;
    } catch (Exception $e) { return true; }
}

/**
 * Envoie un email via Resend. Retourne ['ok'=>bool, 'id'=>?string, 'error'=>?string].
 * @param string $to       destinataire
 * @param string $subject  sujet
 * @param string $html     corps HTML complet
 * @param string $template nom du template (pour log)
 * @param ?int   $userId   user lié (pour log)
 * @param array  $meta     meta json loggé
 * @param bool   $bypassOptOut envoie même si user opt-out (transac critique)
 */
function sendEmail(string $to, string $subject, string $html, string $template = 'custom', ?int $userId = null, array $meta = [], bool $bypassOptOut = false): array {
    ensureEmailSchema();

    if (!emailEnabled()) {
        logEmail($to, $template, $subject, 'failed', null, 'SMTP non configuré', $userId, $meta);
        return ['ok' => false, 'error' => 'SMTP non configuré'];
    }
    if (!$bypassOptOut && !userAllowsEmails($to)) {
        logEmail($to, $template, $subject, 'failed', null, 'Opt-out user', $userId, $meta);
        return ['ok' => false, 'error' => 'Opt-out user'];
    }

    $payload = [
        'from'     => SMTP_FROM_NAME . ' <' . SMTP_FROM_ADDRESS . '>',
        'to'       => [$to],
        'subject'  => $subject,
        'html'     => $html,
        'reply_to' => defined('SMTP_REPLY_TO') ? SMTP_REPLY_TO : SMTP_FROM_ADDRESS,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SMTP_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300) {
        $errMsg = $err ?: ('HTTP ' . $code . ' ' . substr((string) $body, 0, 200));
        logEmail($to, $template, $subject, 'failed', null, $errMsg, $userId, $meta);
        return ['ok' => false, 'error' => $errMsg, 'http_code' => $code];
    }
    $resp = json_decode((string) $body, true);
    $id = $resp['id'] ?? null;
    logEmail($to, $template, $subject, 'sent', $id, null, $userId, $meta);
    return ['ok' => true, 'id' => $id];
}

// ============================================================================
//  Templates HTML — layout commun + templates dédiés.
// ============================================================================

function emailLayout(string $title, string $bodyHtml, ?string $ctaLabel = null, ?string $ctaUrl = null, ?string $footerNote = null): string {
    $cta = '';
    if ($ctaLabel && $ctaUrl) {
        $cta = '<tr><td style="padding:8px 0 24px"><a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES) . '" style="display:inline-block;background:linear-gradient(135deg,#8B5E3C,#A06B45);color:#fff;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:700;font-size:14px;font-family:\'DM Sans\',Arial,sans-serif;box-shadow:0 2px 6px rgba(60,40,20,.2)">' . htmlspecialchars($ctaLabel, ENT_QUOTES) . '</a></td></tr>';
    }
    $footer = $footerNote
        ? '<p style="font-size:11px;color:#8B7F6E;margin:8px 0">' . $footerNote . '</p>'
        : '';
    $titleEsc = htmlspecialchars($title, ENT_QUOTES);
    return '<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>' . $titleEsc . '</title></head>
<body style="margin:0;padding:24px;background:#F0E8D8;font-family:\'DM Sans\',Arial,sans-serif;color:#2A2018">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E0CDAE;border-radius:16px;box-shadow:0 2px 8px rgba(139,94,60,.12)">
<tr><td style="padding:28px 32px 20px;border-bottom:1px solid #F0E8D8">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td style="vertical-align:middle">
      <div style="width:44px;height:44px;border-radius:8px;border:2px solid #8B5E3C;background:linear-gradient(to bottom,#FFFFFF 0%,#F5E8D1 40%,#8B5E3C 100%);display:inline-block;text-align:center;line-height:40px;font-family:Georgia,serif;font-size:22px;color:#8B5E3C;font-weight:bold">O<span style="font-family:\'Brush Script MT\',cursive;color:#0D0A08;font-size:22px">i</span></div>
    </td>
    <td style="vertical-align:middle;padding-left:12px">
      <span style="font-family:Georgia,serif;font-weight:700;font-size:22px;letter-spacing:1.8px;color:#8B5E3C">OCRE</span><span style="font-family:\'Brush Script MT\',cursive;font-size:26px;color:#2A2018;margin-left:4px">immo</span>
    </td>
  </tr></table>
</td></tr>
<tr><td style="padding:28px 32px">
  <h1 style="font-family:Georgia,serif;font-size:26px;color:#8B5E3C;margin:0 0 16px;font-weight:700">' . $titleEsc . '</h1>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14.5px;line-height:1.6;color:#2A2018"><tr><td>' . $bodyHtml . '</td></tr>' . $cta . '</table>
</td></tr>
<tr><td style="padding:16px 32px 24px;border-top:1px solid #F0E8D8;background:#FBF7EE;border-radius:0 0 16px 16px">
  <p style="font-size:12px;color:#8B7F6E;margin:0 0 6px">OCRE immo — logiciel de suivi immobilier pour experts en gestion de patrimoine.</p>
  ' . $footer . '
  <p style="font-size:11px;color:#A89882;margin:6px 0 0"><a href="https://app.ocre.immo/" style="color:#8B5E3C;text-decoration:none">app.ocre.immo</a> · Email envoyé automatiquement, répondez à ce message pour nous joindre.</p>
</td></tr>
</table>
</body></html>';
}

function emailTemplateWelcome(string $prenom): string {
    $p = htmlspecialchars($prenom ?: 'toi', ENT_QUOTES);
    $body = '<p>Bonjour ' . $p . ',</p>'
          . '<p>Bienvenue sur <strong>OCRE immo</strong>. Ton compte est actif. Tu peux dès maintenant créer tes premiers dossiers, gérer tes clients et suivre l\'avancée de tes missions.</p>'
          . '<p>Pour démarrer, clique sur le bouton ci-dessous :</p>';
    return emailLayout('Bienvenue sur OCRE immo', $body, 'Ouvrir OCRE immo', 'https://app.ocre.immo/');
}

function emailTemplateInvitation(string $prenom, string $tempPassword, string $adminName): string {
    $p = htmlspecialchars($prenom ?: '', ENT_QUOTES);
    $a = htmlspecialchars($adminName, ENT_QUOTES);
    $t = htmlspecialchars($tempPassword, ENT_QUOTES);
    $body = '<p>Bonjour ' . $p . ',</p>'
          . '<p>' . $a . ' t\'a créé un compte sur <strong>OCRE immo</strong>.</p>'
          . '<p>Tes identifiants provisoires :</p>'
          . '<p style="background:#FBF7EE;border:1px solid #E0CDAE;border-radius:8px;padding:12px;font-family:monospace;font-size:14px">'
          . '<strong>Mot de passe :</strong> ' . $t . '</p>'
          . '<p>À la première connexion, tu seras invité·e à choisir ton mot de passe définitif.</p>';
    return emailLayout('Invitation sur OCRE immo', $body, 'Me connecter', 'https://app.ocre.immo/login/', 'Ce mot de passe expirera après ta première connexion.');
}

function emailTemplatePasswordReset(string $prenom, string $tempPassword): string {
    $p = htmlspecialchars($prenom ?: '', ENT_QUOTES);
    $t = htmlspecialchars($tempPassword, ENT_QUOTES);
    $body = '<p>Bonjour ' . $p . ',</p>'
          . '<p>Un administrateur a réinitialisé ton mot de passe sur OCRE immo.</p>'
          . '<p>Ton mot de passe temporaire :</p>'
          . '<p style="background:#FBF7EE;border:1px solid #E0CDAE;border-radius:8px;padding:12px;font-family:monospace;font-size:14px"><strong>' . $t . '</strong></p>'
          . '<p>Connecte-toi avec ce mot de passe. Tu seras invité·e à en choisir un nouveau immédiatement.</p>';
    return emailLayout('Mot de passe réinitialisé', $body, 'Me connecter', 'https://app.ocre.immo/login/', 'Tu n\'es pas à l\'origine de cette demande ? Contacte rapidement l\'administrateur.');
}

function emailTemplateRdvReminder(string $prenom, string $clientNom, string $dateFormattee, string $heure, ?string $lieu = null): string {
    $p = htmlspecialchars($prenom ?: '', ENT_QUOTES);
    $c = htmlspecialchars($clientNom, ENT_QUOTES);
    $d = htmlspecialchars($dateFormattee, ENT_QUOTES);
    $h = htmlspecialchars($heure, ENT_QUOTES);
    $l = $lieu ? '<br><strong>Lieu :</strong> ' . htmlspecialchars($lieu, ENT_QUOTES) : '';
    $body = '<p>Bonjour ' . $p . ',</p>'
          . '<p>Rappel de ton rendez-vous :</p>'
          . '<p style="background:#FBF7EE;border:1px solid #E0CDAE;border-radius:8px;padding:14px 16px;font-size:15px">'
          . '<strong>Client :</strong> ' . $c . '<br>'
          . '<strong>Date :</strong> ' . $d . '<br>'
          . '<strong>Heure :</strong> ' . $h
          . $l
          . '</p>';
    return emailLayout('Rappel de rendez-vous', $body, 'Voir le dossier', 'https://app.ocre.immo/');
}
