<?php
// M/2026/05/13/9 — Wrapper email PHPMailer + OVH SMTP authentifie (ssl0.ovh.net:465).
// Pas de fallback "best effort" silencieux : si OVH SMTP echoue, le code retourne
// ok=false avec error detaille → l'appelant declenche _alert_email_failure pour
// notifier le super-admin (Telegram + log + dashboard pending-activations).
//
// Configuration : api/_smtp_config.php (gitignored, perms 600 root:www-data).
// Template committable : api/_smtp_config.example.php.
// Pre-requis DNS (deja OK sur ocre.immo) : SPF (mx.ovh.com), DKIM, DMARC.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

if (!function_exists('send_mail')) {

function send_mail(string $to, string $subject, string $bodyHtml, string $fromEmail = '', string $fromName = '', ?string $bodyText = null): array {
    $logFile = '/var/log/ocre/email-sender.log';
    @mkdir(dirname($logFile), 0755, true);

    $configPath = __DIR__ . '/../_smtp_config.php';
    // M/2026/05/08/39 — is_readable au lieu de file_exists : détecte les perms incorrectes (600 root:www-data
    // alors que PHP-FPM tourne en www-ocre user → require fatal error qui plantait agents_register.php).
    if (!is_readable($configPath)) {
        $err = 'SMTP config missing or unreadable : ' . $configPath . ' (PHP user=' . get_current_user() . ')';
        @file_put_contents($logFile, "[" . date('c') . "] FAIL_CONFIG to=$to err=$err\n", FILE_APPEND);
        return ['ok' => false, 'error' => $err, 'message_id' => null, 'provider' => 'ovh_smtp'];
    }
    try {
        $cfg = require $configPath;
    } catch (Throwable $e) {
        $err = 'SMTP config require failed : ' . $e->getMessage();
        @file_put_contents($logFile, "[" . date('c') . "] FAIL_CONFIG to=$to err=$err\n", FILE_APPEND);
        return ['ok' => false, 'error' => $err, 'message_id' => null, 'provider' => 'ovh_smtp'];
    }
    if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
        $err = 'SMTP config incomplete (host/username/password required)';
        @file_put_contents($logFile, "[" . date('c') . "] FAIL_CONFIG to=$to err=$err\n", FILE_APPEND);
        return ['ok' => false, 'error' => $err, 'message_id' => null, 'provider' => 'ovh_smtp'];
    }

    $effectiveFromEmail = $fromEmail !== '' ? $fromEmail : ($cfg['from_email'] ?? 'noreply@ocre.immo');
    $effectiveFromName  = $fromName  !== '' ? $fromName  : ($cfg['from_name']  ?? 'Oi Agent');

    $mail = new PHPMailer(true); // true = throw exceptions
    try {
        $mail->isSMTP();
        $mail->Host       = (string)$cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$cfg['username'];
        $mail->Password   = (string)$cfg['password'];
        $mail->SMTPSecure = (string)($cfg['encryption'] ?? 'ssl'); // 'ssl' (port 465) ou 'tls' (port 587)
        $mail->Port       = (int)($cfg['port'] ?? 465);
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($effectiveFromEmail, $effectiveFromName);
        $mail->addReplyTo($cfg['reply_to'] ?? 'support@ocre.immo');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText ?: trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyHtml)));

        $mail->send();
        $messageId = $mail->getLastMessageID();
        @file_put_contents($logFile, sprintf("[%s] SENT_OVH to=%s subject=%s message_id=%s\n",
            date('c'), $to, $subject, $messageId
        ), FILE_APPEND);
        return ['ok' => true, 'error' => null, 'message_id' => $messageId, 'provider' => 'ovh_smtp'];
    } catch (PHPMailerException $e) {
        $err = $e->getMessage();
        @file_put_contents($logFile, sprintf("[%s] FAIL_OVH to=%s subject=%s err=%s\n",
            date('c'), $to, $subject, preg_replace('/\s+/', ' ', $err)
        ), FILE_APPEND);
        return ['ok' => false, 'error' => $err, 'message_id' => null, 'provider' => 'ovh_smtp'];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        @file_put_contents($logFile, sprintf("[%s] FAIL_OVH_UNEXPECTED to=%s subject=%s err=%s\n",
            date('c'), $to, $subject, preg_replace('/\s+/', ' ', $err)
        ), FILE_APPEND);
        return ['ok' => false, 'error' => $err, 'message_id' => null, 'provider' => 'ovh_smtp'];
    }
}

}

if (!function_exists('ocre_send_email')) {

// M/2026/05/08/29 → 31 — shim retro-compat : code legacy attend bool.
function ocre_send_email(string $to, string $subject, string $bodyHtml, ?string $bodyText = null): bool {
    $r = send_mail($to, $subject, $bodyHtml, '', '', $bodyText);
    return !empty($r['ok']);
}

}

if (!function_exists('ocre_email_template')) {

function ocre_email_template(string $type, array $vars = []): array {
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'app.ocre.immo');
    $prefsUrl = "{$base}/preferences";
    $css = "font-family: -apple-system, BlinkMacSystemFont, sans-serif; color: #3a2e22; line-height: 1.5;";
    $brand = "color: #8B6F47; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 700;";
    $btn = "display: inline-block; padding: 12px 24px; background: #8B6F47; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600;";
    $footer = "<p style=\"font-size: 11px; color: #999; margin-top: 24px;\">Vous recevez cet email parce que vous êtes membre de Ocre Immo. <a href=\"{$prefsUrl}\">Gérer mes préférences notif</a>.</p>";

    $title = $vars['title'] ?? 'Notification Ocre Immo';
    $body = $vars['body'] ?? '';
    $linkUrl = $vars['link_url'] ?? $base;
    $linkLabel = $vars['link_label'] ?? 'Ouvrir Ocre Immo';

    $html = "<html><body style=\"{$css}\">"
        . "<div style=\"max-width:600px;margin:0 auto;padding:24px;border:1px solid #e8d8b9;border-radius:8px;\">"
        . "<h2 style=\"{$brand}\">{$title}</h2>"
        . "<div style=\"margin: 12px 0; font-size: 14px;\">{$body}</div>"
        . "<a href=\"{$linkUrl}\" style=\"{$btn}\">{$linkLabel}</a>"
        . $footer
        . "</div></body></html>";
    $subject = $vars['subject'] ?? $title;
    return ['subject' => $subject, 'html' => $html];
}

}
