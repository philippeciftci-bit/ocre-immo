<?php
// M97 — Envoi email via OVH SMTP ssl0.ovh.net:465 (SSL/TLS).
// Pas de lib externe : socket SSL + commandes SMTP brutes.

function email_secret(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $pwdPath = '/root/.secrets/ovh-noreply-ocre.pwd';
    if (!is_readable($pwdPath)) {
        error_log('email: pwd file unreadable');
        $cfg = ['ok' => false];
        return $cfg;
    }
    $cfg = [
        'ok' => true,
        'host' => 'ssl0.ovh.net',
        'port' => 465,
        'user' => 'noreply@ocre.immo',
        'pass' => trim(file_get_contents($pwdPath)),
        'from' => 'noreply@ocre.immo',
        'from_name' => 'Ocre Immo',
    ];
    return $cfg;
}

function email_send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool {
    $cfg = email_secret();
    if (!$cfg['ok']) return false;

    $sock = @stream_socket_client(
        'ssl://' . $cfg['host'] . ':' . $cfg['port'],
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
    );
    if (!$sock) { error_log("email: socket fail $errstr"); return false; }

    $read = function() use ($sock) {
        $r = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $r .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return $r;
    };
    $cmd = function($c) use ($sock, $read) {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $ok = true;
    $read();
    if (!preg_match('/^250/', $cmd('EHLO ocre.immo'))) $ok = false;
    if ($ok && !preg_match('/^334/', $cmd('AUTH LOGIN'))) $ok = false;
    if ($ok && !preg_match('/^334/', $cmd(base64_encode($cfg['user'])))) $ok = false;
    if ($ok && !preg_match('/^235/', $cmd(base64_encode($cfg['pass'])))) $ok = false;
    if ($ok && !preg_match('/^250/', $cmd('MAIL FROM:<' . $cfg['from'] . '>'))) $ok = false;
    if ($ok && !preg_match('/^250/', $cmd('RCPT TO:<' . $to . '>'))) $ok = false;
    if ($ok && !preg_match('/^354/', $cmd('DATA'))) $ok = false;

    if ($ok) {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $msg  = "From: " . $cfg['from_name'] . " <" . $cfg['from'] . ">\r\n";
        $msg .= "To: <$to>\r\n";
        $msg .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $msg .= ($bodyText ?: strip_tags($bodyHtml)) . "\r\n\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $msg .= $bodyHtml . "\r\n\r\n";
        $msg .= "--$boundary--\r\n.";
        if (!preg_match('/^250/', $cmd($msg))) $ok = false;
    }
    $cmd('QUIT');
    fclose($sock);

    if (!$ok) error_log("email: SMTP fail to=$to");
    return $ok;
}
