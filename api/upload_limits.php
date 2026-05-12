<?php
// M/2026/05/13/14 — Endpoint public read-only des limites upload (audit M/13/3 Axe 5).
require_once __DIR__ . '/db.php';
setCorsHeaders();
$limits = require __DIR__ . '/lib/upload_limits.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
echo json_encode(['ok' => true, 'limits' => $limits], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
