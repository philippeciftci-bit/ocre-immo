<?php
// M118 — GET /api/calendar/feed.php?token=XXX (signed JWT, no cookie required)
// URL pour abonnement Google Cal/Outlook/Apple Calendar.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_lib.php';
$token = $_GET['token'] ?? '';
$claims = $token ? cal_verify_feed_token($token) : null;
if (!$claims) {
    http_response_code(401); header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Token invalide ou revoke']); exit;
}
$ics = cal_generate_ics($claims['tenant'], (int) $claims['sub']);
header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: max-age=900'); // 15 min cache
echo $ics;
