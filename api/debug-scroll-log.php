<?php
// DEBUG M/14/52 TEMP - a retirer apres diag iOS QuickType scroll.
// Endpoint debug pas d auth. Append JSON brut + timestamp + tenant slug.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$body = file_get_contents('php://input');
if (!$body || strlen($body) > 4096) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'invalid_payload']);
    exit;
}
$ts = date('c');
$tenant = isset($_SERVER['HTTP_X_TENANT']) ? preg_replace('/[^a-z0-9_-]/i', '', $_SERVER['HTTP_X_TENANT']) : 'unknown';
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '';
$line = "[$ts][$tenant] $body | ua=$ua\n";
file_put_contents('/var/log/ocre/scroll-debug.log', $line, FILE_APPEND | LOCK_EX);
echo json_encode(['ok' => true]);
