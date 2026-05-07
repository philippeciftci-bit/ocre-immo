<?php
// M/2026/05/07/93 — Webhook WhatsApp Cloud API Meta.
// GET : handshake verification (Meta envoie hub.mode + hub.verify_token + hub.challenge).
// POST : reception statuts livraison (sent/delivered/read/failed) + messages entrants
//        (gestion opt-out STOP automatique).
//
// URL publique : https://signup.ocre.immo/api/whatsapp_webhook.php
// Verify token : /root/.secrets/whatsapp-meta.env -> WHATSAPP_WEBHOOK_VERIFY_TOKEN

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/whatsapp_sender.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// === Handshake GET (Meta verification) ===
if ($method === 'GET') {
    $creds = _ocre_wa_load_creds();
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $creds['verify_token'] !== '' && hash_equals($creds['verify_token'], $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'forbidden';
    exit;
}

// === POST : statuts + messages entrants ===
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

@file_put_contents('/var/log/ocre/whatsapp.log',
    '[' . date('c') . '] WEBHOOK_IN ' . substr($raw, 0, 1000) . "\n", FILE_APPEND);

try {
    $pdo = _ocre_wa_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$entries = $body['entry'] ?? [];
$processed = 0;

foreach ($entries as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $value = $change['value'] ?? [];

        // (a) Statuts livraison
        foreach ($value['statuses'] ?? [] as $st) {
            $msgId = $st['id'] ?? '';
            $status = $st['status'] ?? '';
            $tsCol = match($status) {
                'sent' => 'sent_at',
                'delivered' => 'delivered_at',
                'read' => 'read_at',
                default => null,
            };
            $allowedStatus = ['sent','delivered','read','failed','undelivered'];
            if (!in_array($status, $allowedStatus, true) || $msgId === '') continue;
            try {
                if ($tsCol) {
                    $sql = "UPDATE whatsapp_events SET status = ?, $tsCol = NOW() WHERE provider_message_id = ?";
                    $pdo->prepare($sql)->execute([$status, $msgId]);
                } else {
                    $pdo->prepare("UPDATE whatsapp_events SET status = ?, error_message = ? WHERE provider_message_id = ?")
                        ->execute([$status, substr(json_encode($st['errors'] ?? []), 0, 500), $msgId]);
                }
                $processed++;
            } catch (Throwable $e) { /* silent */ }
        }

        // (b) Messages entrants : detection STOP/UNSUBSCRIBE -> opt-out
        foreach ($value['messages'] ?? [] as $msg) {
            $from = '+' . preg_replace('/\D/', '', $msg['from'] ?? '');
            $text = strtoupper(trim($msg['text']['body'] ?? ''));
            if (in_array($text, ['STOP','ARRET','UNSUBSCRIBE','DESABONNEMENT','DESINSCRIPTION'], true)) {
                try {
                    $pdo->prepare("UPDATE users SET notif_whatsapp_enabled = 0 WHERE whatsapp = ? OR whatsapp = ?")
                        ->execute([$from, ltrim($from, '+')]);
                    @file_put_contents('/var/log/ocre/whatsapp.log',
                        '[' . date('c') . "] OPT_OUT phone=$from text=$text\n", FILE_APPEND);
                    $processed++;
                } catch (Throwable $e) { /* silent */ }
            }
        }
    }
}

echo json_encode(['ok' => true, 'processed' => $processed]);
