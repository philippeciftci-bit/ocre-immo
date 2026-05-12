<?php
// M107 — POST /api/billing/webhook.php
// Endpoint webhook interne Ocre. Verifie signature STRIPE_WEBHOOK_SECRET (HMAC-SHA256).
// Gere events : customer.subscription.created/updated/deleted + invoice.paid/payment_failed.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/billing_channel.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('method');
}

$raw = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$env = billing_load_stripe_env();
$secret = $env['STRIPE_WEBHOOK_SECRET'] ?? '';
$useMock = empty($env['_configured']) || !empty($env['STRIPE_USE_MOCK']);

// Verification signature (skip si mode mock + flag explicite)
if (!$useMock && $secret) {
    $valid = false;
    if (preg_match('/t=(\d+),v1=([a-f0-9]+)/', $sigHeader, $m)) {
        $t = $m[1]; $v1 = $m[2];
        $payload = $t . '.' . $raw;
        $expected = hash_hmac('sha256', $payload, $secret);
        $valid = hash_equals($expected, $v1);
    }
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_signature']);
        exit;
    }
}

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_event']);
    exit;
}

bch_ensure_schema();
$db = bch_meta_pdo();
$type = $event['type'];
$obj = $event['data']['object'] ?? [];
$tenant = $obj['metadata']['tenant_slug'] ?? null;

$wlog = function (string $msg) use ($type) {
    @file_put_contents('/var/log/ocre-channel-webhook.log', '[' . date('c') . '] [' . $type . '] ' . $msg . "\n", FILE_APPEND);
};

switch ($type) {
    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        if (!$tenant) { http_response_code(200); echo json_encode(['skip' => 'no_tenant']); exit; }
        $now = time();
        $periodStart = isset($obj['current_period_start']) ? date('Y-m-d H:i:s', (int) $obj['current_period_start']) : null;
        $periodEnd = isset($obj['current_period_end']) ? date('Y-m-d H:i:s', (int) $obj['current_period_end']) : null;
        $featuresUntil = $periodEnd;
        $status = $obj['status'] ?? 'active';
        $channelActive = in_array($status, ['active', 'trialing']);
        $cancelAtEnd = !empty($obj['cancel_at_period_end']) ? 1 : 0;
        $db->prepare(
            "INSERT INTO billing_subscriptions (tenant_slug, user_id, stripe_customer_id, stripe_subscription_id, plan_channel_active, channel_features_until, current_period_start, current_period_end, cancel_at_period_end, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               stripe_customer_id=VALUES(stripe_customer_id),
               stripe_subscription_id=VALUES(stripe_subscription_id),
               plan_channel_active=VALUES(plan_channel_active),
               channel_features_until=VALUES(channel_features_until),
               current_period_start=VALUES(current_period_start),
               current_period_end=VALUES(current_period_end),
               cancel_at_period_end=VALUES(cancel_at_period_end),
               status=VALUES(status)"
        )->execute([
            $tenant,
            (int) ($obj['metadata']['user_id'] ?? 0),
            $obj['customer'] ?? null,
            $obj['id'] ?? null,
            $channelActive ? 1 : 0,
            $featuresUntil,
            $periodStart,
            $periodEnd,
            $cancelAtEnd,
            $status,
        ]);
        $wlog("tenant=$tenant subscription=" . ($obj['id'] ?? '?') . " status=$status active=$channelActive");
        break;

    case 'customer.subscription.deleted':
        if (!$tenant) { http_response_code(200); echo json_encode(['skip' => 'no_tenant']); exit; }
        $db->prepare("UPDATE billing_subscriptions SET status='canceled', plan_channel_active=0 WHERE tenant_slug=?")->execute([$tenant]);
        $wlog("tenant=$tenant CANCELED");
        break;

    case 'invoice.paid':
        if (!$tenant) { http_response_code(200); echo json_encode(['skip' => 'no_tenant']); exit; }
        $db->prepare(
            "INSERT INTO billing_invoices (tenant_slug, stripe_invoice_id, amount_eur, status, paid_at, pdf_url)
             VALUES (?, ?, ?, 'paid', NOW(), ?)
             ON DUPLICATE KEY UPDATE status='paid', paid_at=NOW()"
        )->execute([
            $tenant,
            $obj['id'] ?? null,
            isset($obj['amount_paid']) ? (float) $obj['amount_paid'] / 100.0 : null,
            $obj['hosted_invoice_url'] ?? null,
        ]);
        // Push notif PWA (pattern M88)
        @file_get_contents('https://app.ocre.immo/api/push_notify.php?token=' . urlencode($_ENV['OCRE_PUSH_TOKEN'] ?? '') . '&tenant=' . urlencode($tenant) . '&title=' . urlencode('Facture payée') . '&body=' . urlencode('Channel Premium · ' . (isset($obj['amount_paid']) ? round($obj['amount_paid']/100, 2) . ' EUR' : 'OK')));
        $wlog("tenant=$tenant invoice " . ($obj['id'] ?? '?') . " PAID");
        break;

    case 'invoice.payment_failed':
        if (!$tenant) { http_response_code(200); echo json_encode(['skip' => 'no_tenant']); exit; }
        $db->prepare(
            "INSERT INTO billing_invoices (tenant_slug, stripe_invoice_id, amount_eur, status)
             VALUES (?, ?, ?, 'failed')
             ON DUPLICATE KEY UPDATE status='failed'"
        )->execute([
            $tenant,
            $obj['id'] ?? null,
            isset($obj['amount_due']) ? (float) $obj['amount_due'] / 100.0 : null,
        ]);
        $wlog("tenant=$tenant invoice " . ($obj['id'] ?? '?') . " FAILED");
        break;

    default:
        $wlog("unhandled type tenant=" . ($tenant ?: 'unknown'));
}

http_response_code(200);
echo json_encode(['ok' => true, 'type' => $type]);
