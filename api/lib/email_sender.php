<?php
// M/2026/04/28/58 — Envoi email réel via msmtp Gmail. Fallback log stub si pas d'App Password.
if (!function_exists('ocre_send_email')) {

function ocre_send_email(string $to, string $subject, string $bodyHtml, ?string $bodyText = null): bool {
    $passwordFile = '/root/.secrets/gmail_app_password';
    $logStub = '/var/log/ocre-edit-notifs.log';
    if (!is_file($passwordFile) || filesize($passwordFile) < 5) {
        @file_put_contents($logStub, sprintf("[%s] STUB to=%s subject=%s body=%s\n",
            date('c'), $to, $subject, mb_substr(strip_tags($bodyHtml), 0, 200)
        ), FILE_APPEND);
        return false;
    }
    if (!is_executable('/usr/bin/msmtp')) {
        @file_put_contents($logStub, "[" . date('c') . "] FAIL msmtp not installed\n", FILE_APPEND);
        return false;
    }
    $boundary = 'ocre-' . bin2hex(random_bytes(8));
    $textBody = $bodyText ?: trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyHtml)));
    $headers = [
        'From: Ocre Immo <notif@ocre.immo>',
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Date: ' . date('r'),
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
        '/usr/bin/msmtp -a ocre_notif -- ' . escapeshellarg($to),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) return false;
    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        @file_put_contents($logStub, sprintf("[%s] FAIL msmtp exit=%d stderr=%s\n", date('c'), $exit, mb_substr($stderr, 0, 300)), FILE_APPEND);
        return false;
    }
    return true;
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
