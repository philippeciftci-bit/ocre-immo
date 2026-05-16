<?php
// M/2026/04/29/47 — Endpoint version : retourne le BUILD_VERSION courant deploye.
// M/2026/05/16/2 — lit desormais /opt/ocre-app/.build-version (source unique BUILD_ID,
// pattern Vercel) au lieu d'un echo hardcode patche par sed. Fin de la desync structurelle :
// version.php et build-version.php lisent le MEME fichier, impossible de diverger.
// Lu par le script auto-invalidation client (couche 4) toutes les 30s + visibilitychange.
// Headers no-cache stricts pour forcer un fetch reseau a chaque check.

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');

echo trim(@file_get_contents(__DIR__ . '/../.build-version')) ?: 'unknown';
