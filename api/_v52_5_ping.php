<?php
// V52.5 — ping diagnostic. Pas d'auth requise. Log toute requête dans _diag.log.
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('Content-Type: text/plain');
$line = sprintf("[DIAG-%s] PING ip=%s stage=%s ua=%s href=%s referer=%s\n",
    date('c'),
    $_SERVER['REMOTE_ADDR'] ?? '',
    $_GET['stage'] ?? '',
    substr($_GET['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80),
    substr($_GET['href'] ?? '', 0, 200),
    substr($_SERVER['HTTP_REFERER'] ?? '', 0, 200)
);
@file_put_contents(__DIR__ . '/_diag.log', $line, FILE_APPEND | LOCK_EX);
echo "ok\n";
