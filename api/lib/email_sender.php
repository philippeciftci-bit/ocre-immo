<?php
// M/2026/05/08/29 — Refonte SMTP fiable. Ordre :
//   1. Resend HTTP API (cle dans api/_smtp_config.php, hors-git)
//   2. Fallback sendmail local (Postfix DKIM ocre.immo) si Resend echoue
// L echec Postfix silencieux (mail() true mais delivery KO) etait la cause
// racine signalee par Philippe le 2026-05-08 10:18-10:20 (2 inscriptions sans
// reception de mail). Resend API retourne un message_id si delivery est accepte
// par leur infra (pas de "false positive" comme sendmail local).

if (!function_exists('send_mail')) {

function send_mail(string $to, string $subject, string $bodyHtml, string $fromEmail = 'noreply@ocre.immo', string $fromName = 'Oi Agent', ?string $bodyText = null): array {
    $logFile = '/var/log/ocre/email-sender.log';
    @mkdir(dirname($logFile), 0755, true);

    // 1. Tentative Resend HTTP API
    $configPath = __DIR__ . '/../_smtp_config.php';
    $resendOk = false;
    $resendErr = null;
    $messageId = null;
    if (file_exists($configPath)) {
        require_once $configPath;
        if (defined('SMTP_PROVIDER') && SMTP_PROVIDER === 'resend' && defined('SMTP_API_KEY')) {
            $resendFromEmail = defined('SMTP_FROM_ADDRESS') ? SMTP_FROM_ADDRESS : $fromEmail;
            $resendFromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : $fromName;
            $resendReplyTo   = defined('SMTP_REPLY_TO') ? SMTP_REPLY_TO : 'support@ocre.immo';
            $payload = json_encode([
                'from' => $resendFromName . ' <' . $resendFromEmail . '>',
                'to' => [$to],
                'subject' => $subject,
                'html' => $bodyHtml,
                'reply_to' => $resendReplyTo,
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . SMTP_API_KEY,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode((string)$response, true);
                $messageId = is_array($data) && isset($data['id']) ? (string)$data['id'] : null;
                $resendOk = true;
                @file_put_contents($logFile, sprintf("[%s] SENT_RESEND to=%s subject=%s message_id=%s\n",
                    date('c'), $to, $subject, $messageId ?: '?'
                ), FILE_APPEND);
                return ['ok' => true, 'error' => null, 'message_id' => $messageId, 'provider' => 'resend'];
            }
            $resendErr = ($curlErr ?: 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
            @file_put_contents($logFile, sprintf("[%s] FAIL_RESEND to=%s subject=%s err=%s\n",
                date('c'), $to, $subject, $resendErr
            ), FILE_APPEND);
        }
    }

    // 2. Fallback sendmail local (Postfix + DKIM atelier)
    $sendmail = '/usr/sbin/sendmail';
    if (!is_executable($sendmail)) {
        @file_put_contents($logFile, "[" . date('c') . "] FAIL sendmail not executable at $sendmail (resend_err=$resendErr)\n", FILE_APPEND);
        return ['ok' => false, 'error' => 'No mail transport available (resend: ' . ($resendErr ?: 'not configured') . ', sendmail: missing)', 'message_id' => null, 'provider' => null];
    }
    $boundary = 'ocre-' . bin2hex(random_bytes(8));
    $textBody = $bodyText ?: trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyHtml)));
    $localMessageId = '<' . bin2hex(random_bytes(8)) . '.' . time() . '@ocre.immo>';
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: support@ocre.immo',
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Date: ' . gmdate('r'),
        'Message-ID: ' . $localMessageId,
        'X-Mailer: Ocre Immo SMTP/1.0',
        'List-Unsubscribe: <mailto:support@ocre.immo?subject=unsubscribe>',
    ];
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $bodyHtml . "\r\n";
    $body .= "--{$boundary}--\r\n";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    $proc = proc_open(
        $sendmail . ' -t -f ' . escapeshellarg($fromEmail),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        @file_put_contents($logFile, "[" . date('c') . "] FAIL proc_open (resend_err=$resendErr)\n", FILE_APPEND);
        return ['ok' => false, 'error' => 'proc_open failed; resend_err=' . ($resendErr ?: 'n/a'), 'message_id' => null, 'provider' => null];
    }
    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        @file_put_contents($logFile, sprintf("[%s] FAIL_SENDMAIL exit=%d to=%s subject=%s stderr=%s (resend_err=%s)\n",
            date('c'), $exit, $to, $subject, mb_substr((string)$stderr, 0, 300), $resendErr ?? 'n/a'
        ), FILE_APPEND);
        return ['ok' => false, 'error' => 'sendmail exit ' . $exit . '; resend_err=' . ($resendErr ?: 'n/a'), 'message_id' => null, 'provider' => null];
    }
    @file_put_contents($logFile, sprintf("[%s] SENT_SENDMAIL to=%s subject=%s msgid=%s (resend_err=%s)\n",
        date('c'), $to, $subject, $localMessageId, $resendErr ?? 'n/a'
    ), FILE_APPEND);
    return ['ok' => true, 'error' => null, 'message_id' => $localMessageId, 'provider' => 'sendmail'];
}

}

if (!function_exists('ocre_send_email')) {

// M/2026/05/08/29 — shim de retro-compatibilite : code legacy attend bool.
// Delegue a send_mail() qui retourne {ok, error, message_id}.
function ocre_send_email(string $to, string $subject, string $bodyHtml, ?string $bodyText = null): bool {
    $r = send_mail($to, $subject, $bodyHtml, 'noreply@ocre.immo', 'Oi Agent', $bodyText);
    return !empty($r['ok']);
}

function ocre_email_template(string $type, array $vars = []): array {
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'zefk.ocre.immo');
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
