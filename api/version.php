<?php
// M/2026/04/29/47 — Endpoint version : retourne le BUILD_VERSION courant deploye.
// Le deploy script remplace ed1db32-1778707092 ci-dessous par <SHA>-<timestamp> a chaque rsync.
// Lu par le script auto-invalidation client (couche 4) toutes les 30s + visibilitychange.
// Headers no-cache stricts pour forcer un fetch reseau a chaque check.

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');

echo '__BUILD_VERSION__';
