<?php
// M118 — GET /api/calendar/export.php (auth cookie, returns ICS file download)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) {
    http_response_code(401); header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Non authentifie']); exit;
}
$tenant = $user['slug'];
$ics = cal_generate_ics($tenant, (int) $user['user_id']);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="oi-agent-rdv-' . $tenant . '-' . date('Ymd') . '.ics"');
echo $ics;
