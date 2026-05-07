<?php
// M/2026/05/07/93 — Endpoint POST envoi WhatsApp transactionnel.
// Auth via header X-Internal-Token (cron interne, jamais expose en clair public).
// Le secret est lu depuis /root/.secrets/ocre-internal-cron.token (mode 600).
//
// POST JSON :
//   {phone: "+33651325177", template: "inscription_confirmee", params: ["Phil", "https://..."], user_id?: 123}
// -> 200 {ok:true, status:"sent"|"stub", provider_message_id?: "...", event_id: N}
// -> 401 {ok:false, error:"unauthorized"}
// -> 422 {ok:false, error:"phone_invalid"|"template_required"}
// -> 500 {ok:false, error:"send_failed", detail}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/whatsapp_sender.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError('Methode non autorisee', 405);
}

$tokenSent = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
$tokenFile = '/etc/ocre/internal-cron.token';
$expected = is_readable($tokenFile) ? trim((string)@file_get_contents($tokenFile)) : '';
if ($expected === '' || !hash_equals($expected, $tokenSent)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input = getInput();
$phone = trim((string)($input['phone'] ?? ''));
$template = trim((string)($input['template'] ?? ''));
$params = is_array($input['params'] ?? null) ? $input['params'] : [];
$userId = isset($input['user_id']) ? (int)$input['user_id'] : null;

if ($phone === '') jsonError('phone_required', 422);
if ($template === '') jsonError('template_required', 422);
if (!preg_match('/^[a-z0-9_]{1,64}$/', $template)) jsonError('template_invalid', 422);

$r = ocre_whatsapp_send($phone, $template, $params, $userId);
http_response_code($r['ok'] ? 200 : 500);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
