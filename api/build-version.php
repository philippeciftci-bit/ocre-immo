<?php
// M/2026/05/16/2 — BUILD_ID unique (pattern Vercel). Source de verite : /opt/ocre-app/.build-version
// (ecrit par ocre-deploy.sh apres rsync). Sert window.APP_VERSION au client AVANT le
// version-check couche 4. Fin de la desync structurelle index.html / version.php.
// Extension .php (PAS .js) : nginx ocre-app.conf route .js en statique (try_files) AVANT
// le bloc fastcgi PHP — un .js contenant du PHP serait servi en source brute. .php est
// route correctement par `location ~ \.php$`.

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$v = trim(@file_get_contents(__DIR__ . '/../.build-version')) ?: 'unknown';
echo 'window.APP_VERSION = ' . json_encode($v, JSON_UNESCAPED_SLASHES) . ";\n";
