<?php
// V52.5 — wrapper PHP pour servir sw.js avec headers Cache-Control strict
// (les .htaccess seuls ne suffisent pas, vhost OVH ré-injecte max-age=900
// + Expires sur les .js).
header_remove('Cache-Control');
header_remove('Expires');
header_remove('Pragma');
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
$path = __DIR__ . '/sw.js';
if (file_exists($path)) {
    readfile($path);
} else {
    echo "// sw.js absent\n";
}
